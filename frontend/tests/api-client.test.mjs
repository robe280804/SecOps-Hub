import { test } from 'node:test'
import assert from 'node:assert/strict'
import { createApiClient, ApiError } from '../src/shared/api/client.ts'

const makeClient = (fetcher, overrides = {}) => createApiClient({
  origin: 'https://api.example.com', readCookie: () => 'XSRF-TOKEN=encoded%3Dvalue', fetcher, ...overrides,
})

test('sends credentials and decoded CSRF on writes', async () => {
  const client = makeClient(async (url, options) => {
    assert.equal(url.href, 'https://api.example.com/api/v1/session')
    assert.equal(options.credentials, 'include')
    assert.equal(options.cache, 'no-store')
    assert.equal(options.redirect, 'error')
    assert.equal(options.headers.get('X-XSRF-TOKEN'), 'encoded=value')
    assert.equal(options.headers.has('Authorization'), false)
    return new Response(null, { status: 204 })
  })
  assert.equal(await client.request('/api/v1/session', { method: 'DELETE' }), undefined)
})

test('CSRF bootstrap is a credentialed GET', async () => {
  const client = makeClient(async (url, options) => {
    assert.equal(url.pathname, '/sanctum/csrf-cookie')
    assert.equal(options.method, 'GET')
    assert.equal(options.headers.has('X-XSRF-TOKEN'), false)
    return new Response(null, { status: 204 })
  })
  await client.csrf()
})

test('blocks foreign origins and path traversal before sending credentials', async () => {
  const client = makeClient(() => assert.fail('must not fetch'))
  for (const path of ['https://evil.example/api/v1/me', '//evil.example', '/api/v1/../../outside', '/api/v1/me?redirect=evil']) {
    await assert.rejects(client.request(path), ApiError)
  }
})

test('preserves validation field errors and drops malformed entries', async () => {
  const client = makeClient(async () => Response.json({ errors: { email: ['Invalid credentials'], password: 'bad shape' } }, { status: 422 }))
  await assert.rejects(client.request('/api/v1/session'), (error) => {
    assert.deepEqual(error.errors, { email: ['Invalid credentials'] })
    return error.status === 422
  })
})

test('notifies the auth boundary about 401 and 419 without replaying a write', async () => {
  for (const status of [401, 419]) {
    let calls = 0
    let expired = 0
    const client = makeClient(async () => { calls++; return new Response(null, { status }) }, { onSessionExpired: () => expired++ })
    await assert.rejects(client.request('/api/v1/session', { method: 'DELETE' }), ApiError)
    assert.equal(calls, 1)
    assert.equal(expired, 1)
  }
})

test('redacts server diagnostics and retains rate-limit retry metadata', async () => {
  const client = makeClient(async () => Response.json({ message: 'SQL password leak' }, { status: 500 }))
  await assert.rejects(client.request('/api/v1/me'), (error) => !error.message.includes('SQL') && error.status === 500)
  const limited = makeClient(async () => new Response(null, { status: 429, headers: { 'Retry-After': '60' } }))
  await assert.rejects(limited.request('/api/v1/me'), (error) => error.retryAfter === '60')
})

test('fails safely for network and malformed successful responses', async () => {
  const offline = makeClient(async () => { throw new TypeError('private network detail') })
  await assert.rejects(offline.request('/api/v1/me'), (error) => error.status === 0 && !error.message.includes('private'))
  const html = makeClient(async () => new Response('<html>login</html>'))
  await assert.rejects(html.request('/api/v1/me'), (error) => error.status === 502)
})

test('blocks mutations without a CSRF cookie', async () => {
  const client = makeClient(() => assert.fail('must not fetch'), { readCookie: () => '' })
  await assert.rejects(client.request('/api/v1/session', { method: 'POST' }), (error) => error.status === 419)
})

test('normalizes timeouts and preserves caller cancellation', async () => {
  const fetcher = async (_url, { signal }) => new Promise((_resolve, reject) => {
    if (signal.aborted) reject(signal.reason)
    else signal.addEventListener('abort', () => reject(signal.reason), { once: true })
  })
  // Keep the test process alive while AbortSignal.timeout uses an unreferenced timer.
  const keepAlive = setTimeout(() => {}, 1000)
  try {
    const client = makeClient(fetcher, { timeoutMs: 5 })
    await assert.rejects(client.request('/api/v1/me'), (error) => error.message.includes('timed out'))
    const controller = new AbortController()
    controller.abort()
    await assert.rejects(client.request('/api/v1/me', { signal: controller.signal }), (error) => error.name === 'AbortError')
  } finally { clearTimeout(keepAlive) }
})

test('malformed cookies fail safely without sending a request', async () => {
  const client = makeClient(() => assert.fail('must not fetch'), { readCookie: () => 'XSRF-TOKEN=%invalid' })
  await assert.rejects(client.request('/api/v1/session', { method: 'DELETE' }), (error) => error.status === 419)
})

test('encodes query parameters without allowing them to change the request origin or path', async () => {
  const client = makeClient(async (url) => {
    assert.equal(url.origin, 'https://api.example.com')
    assert.equal(url.pathname, '/api/v1/projects')
    assert.equal(url.searchParams.get('page'), '2')
    assert.equal(url.searchParams.get('search'), '//evil.example/?secret=value#fragment')
    return Response.json({ data: [] })
  })
  await client.request('/api/v1/projects', { query: { page: 2, search: '//evil.example/?secret=value#fragment' } })
})

test('describes state conflicts without exposing backend messages or retrying writes', async () => {
  let calls = 0
  const client = makeClient(async () => {
    calls++
    return Response.json({ message: 'Internal diagnostics' }, { status: 409 })
  })
  await assert.rejects(client.request('/api/v1/projects/1', { method: 'PATCH', body: { name: 'Edit' } }),
    (error) => error.status === 409 && error.message.includes('Reload') && !error.message.includes('diagnostics'))
  assert.equal(calls, 1)
})
