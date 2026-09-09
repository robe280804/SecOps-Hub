import { test } from 'node:test'
import assert from 'node:assert/strict'
import { createApiClient } from '../src/shared/api/client.ts'
import { createMembershipApi } from '../src/features/projects/api/membershipApi.ts'
import { collaboratorFormSchema } from '../src/features/projects/model/membershipSchema.ts'

const user = { id: 4, name: 'Analyst', email: 'analyst+project@example.com' }
const membership = {
  id: 8, project_id: 12, access_level: 'viewer', user,
  created_at: '2026-09-09T12:00:00Z', updated_at: '2026-09-09T12:00:00Z',
}
const makeApi = (fetcher, options = {}) => createMembershipApi(createApiClient({
  origin: 'https://api.example.com', readCookie: () => 'XSRF-TOKEN=csrf', fetcher, ...options,
}))

test('paginates memberships within the requested project without following response links', async () => {
  const api = makeApi(async (url) => {
    assert.equal(url.href, 'https://api.example.com/api/v1/projects/12/memberships?page=2')
    return Response.json({ data: [membership], meta: { current_page: 2, last_page: 2, total: 16 }, links: { next: 'https://evil.example' } })
  })
  const result = await api.list(12, 2)
  assert.equal(result.data[0].id, 8)
  assert.equal('links' in result, false)
})

test('looks up an exact email and safely encodes plus addressing', async () => {
  const api = makeApi(async (url, options) => {
    assert.equal(url.pathname, '/api/v1/projects/12/collaborator-lookup')
    assert.equal(url.searchParams.get('email'), user.email)
    assert.equal(options.method, 'GET')
    assert.equal(options.credentials, 'include')
    assert.equal(options.cache, 'no-store')
    return Response.json({ data: [user] })
  })
  assert.deepEqual(await api.lookup(12, ' ' + user.email + ' '), [user])
  assert.deepEqual(await makeApi(async () => Response.json({ data: [] })).lookup(12, user.email), [])
})

test('adds existing users with a default viewer role and preserves CSRF protection', async () => {
  const calls = []
  const api = makeApi(async (url, options) => {
    calls.push(url.pathname)
    if (url.pathname === '/sanctum/csrf-cookie') return new Response(null, { status: 204 })
    assert.equal(options.method, 'POST')
    assert.equal(options.headers.get('X-XSRF-TOKEN'), 'csrf')
    assert.deepEqual(JSON.parse(options.body), { user_id: 4, access_level: 'viewer' })
    return Response.json({ data: membership }, { status: 201 })
  })
  assert.deepEqual(await api.add(12, 4), membership)
  assert.deepEqual(calls, ['/sanctum/csrf-cookie', '/api/v1/projects/12/memberships'])
})

test('changes only the access level and revokes a membership with DELETE', async () => {
  const writes = []
  const api = makeApi(async (url, options) => {
    if (url.pathname === '/sanctum/csrf-cookie') return new Response(null, { status: 204 })
    assert.equal(url.pathname, '/api/v1/projects/12/memberships/8')
    writes.push({ method: options.method, body: options.body && JSON.parse(options.body) })
    return options.method === 'DELETE' ? new Response(null, { status: 204 }) : Response.json({ data: { ...membership, access_level: 'contributor' } })
  })
  assert.equal((await api.update(12, 8, 'contributor')).access_level, 'contributor')
  assert.equal(await api.remove(12, 8), undefined)
  assert.deepEqual(writes, [{ method: 'PATCH', body: { access_level: 'contributor' } }, { method: 'DELETE', body: undefined }])
})

test('rejects invalid IDs roles and lookup emails before making requests', async () => {
  const api = makeApi(() => assert.fail('must not fetch'))
  for (const id of [0, -1, 1.5, NaN, Infinity, '../users', Number.MAX_SAFE_INTEGER + 1]) {
    await assert.rejects(api.list(id))
    await assert.rejects(api.list(12, id))
    await assert.rejects(api.add(12, id))
    await assert.rejects(api.update(12, id, 'viewer'))
    await assert.rejects(api.remove(12, id))
  }
  await assert.rejects(api.add(12, 4, 'admin'))
  await assert.rejects(api.update(12, 8, 'owner'))
  await assert.rejects(api.lookup(12, ''))
  await assert.rejects(api.lookup(12, 'analyst'))
  assert.equal(collaboratorFormSchema.safeParse({ email: 'a'.repeat(256) + '@example.com', access_level: 'viewer' }).success, false)
})

test('rejects malformed collaborator responses and strips unexpected account fields', async () => {
  const leaked = { ...user, password: 'private', role: 'admin' }
  assert.deepEqual(await makeApi(async () => Response.json({ data: [leaked] })).lookup(12, user.email), [user])
  await assert.rejects(makeApi(async () => Response.json({ data: [user, user] })).lookup(12, user.email), (error) => error.status === 502)
  await assert.rejects(makeApi(async () => Response.json({ data: [{ ...user, id: '4' }] })).lookup(12, user.email), (error) => error.status === 502)
  await assert.rejects(makeApi(async () => Response.json({ data: [{ ...membership, access_level: 'admin' }], meta: { current_page: 1, last_page: 1, total: 1 } })).list(12), (error) => error.status === 502)
})

test('preserves duplicate-member errors and does not retry rejected mutations', async () => {
  for (const status of [403, 404, 409, 419, 422]) {
    let writes = 0
    const api = makeApi(async (url) => {
      if (url.pathname === '/sanctum/csrf-cookie') return new Response(null, { status: 204 })
      writes++
      return Response.json({ errors: { user_id: ['This user is already a collaborator.'] } }, { status })
    })
    await assert.rejects(api.add(12, 4), (error) => {
      assert.equal(error.status, status)
      if (status === 422) assert.deepEqual(error.errors.user_id, ['This user is already a collaborator.'])
      return true
    })
    assert.equal(writes, 1)
  }
})

test('surfaces lookup throttling with retry metadata', async () => {
  const api = makeApi(async () => new Response(null, { status: 429, headers: { 'Retry-After': '60' } }))
  await assert.rejects(api.lookup(12, user.email), (error) => error.status === 429 && error.retryAfter === '60')
})
