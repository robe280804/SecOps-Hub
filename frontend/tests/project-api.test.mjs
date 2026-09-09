import { test } from 'node:test'
import assert from 'node:assert/strict'
import { createApiClient } from '../src/shared/api/client.ts'
import { createProjectApi } from '../src/features/projects/api/projectApi.ts'
import { projectFormSchema } from '../src/features/projects/model/projectSchema.ts'

const project = {
  id: 12, user_id: 5, name: 'Assessment', description: null, type: 'company', status: 'inactive',
  created_at: '2026-09-09T12:00:00Z', updated_at: '2026-09-09T12:00:00Z',
}
const fields = { name: ' Assessment ', description: '  ', type: 'company', status: 'inactive' }
const makeApi = (fetcher) => createProjectApi(createApiClient({
  origin: 'https://api.example.com', readCookie: () => 'XSRF-TOKEN=csrf', fetcher,
}))

test('requests a numbered project page and validates pagination without following response links', async () => {
  const api = makeApi(async (url) => {
    assert.equal(url.href, 'https://api.example.com/api/v1/projects?page=2')
    return Response.json({ data: [project], meta: { current_page: 2, last_page: 2, total: 16 }, links: { next: 'https://evil.example' } })
  })
  const result = await api.list(2)
  assert.equal(result.data[0].id, 12)
  assert.equal(result.meta.total, 16)
  assert.equal('links' in result, false)
})

test('reads project details and rejects malformed server contracts', async () => {
  const api = makeApi(async (url) => {
    assert.equal(url.pathname, '/api/v1/projects/12')
    return Response.json({ data: project })
  })
  assert.deepEqual(await api.get(12), project)
  for (const body of [{ data: { ...project, status: 'unknown' } }, { data: { ...project, user_id: '5' } }, { data: null }]) {
    await assert.rejects(makeApi(async () => Response.json(body)).get(12), (error) => error.status === 502)
  }
  await assert.rejects(makeApi(async () => Response.json({ data: [], meta: { total: 1 } })).list(), (error) => error.status === 502)
})

test('creates and updates only allowed fields after refreshing CSRF', async () => {
  for (const method of ['POST', 'PATCH']) {
    const calls = []
    const api = makeApi(async (url, options) => {
      calls.push(url.pathname)
      if (url.pathname === '/sanctum/csrf-cookie') return new Response(null, { status: 204 })
      assert.equal(options.method, method)
      assert.equal(options.credentials, 'include')
      assert.equal(options.headers.get('X-XSRF-TOKEN'), 'csrf')
      assert.deepEqual(JSON.parse(options.body), { name: 'Assessment', description: null, type: 'company', status: 'inactive' })
      return Response.json({ data: project }, { status: method === 'POST' ? 201 : 200 })
    })
    const malicious = { ...fields, user_id: 999, id: 999, memberships: [{ user_id: 999 }], created_at: 'fake' }
    const saved = method === 'POST' ? await api.create(malicious) : await api.update(12, malicious)
    assert.equal(saved.user_id, 5)
    assert.deepEqual(calls, ['/sanctum/csrf-cookie', method === 'POST' ? '/api/v1/projects' : '/api/v1/projects/12'])
  }
})

test('archives with DELETE and reactivates with a status-only PATCH', async () => {
  const writes = []
  const api = makeApi(async (url, options) => {
    if (url.pathname === '/sanctum/csrf-cookie') return new Response(null, { status: 204 })
    assert.equal(url.pathname, '/api/v1/projects/12')
    writes.push({ method: options.method, body: options.body && JSON.parse(options.body) })
    return options.method === 'DELETE' ? new Response(null, { status: 204 }) : Response.json({ data: { ...project, status: JSON.parse(options.body).status } })
  })
  await api.archive(12)
  await api.reactivate(12, 'inactive')
  await api.reactivate(12, 'active')
  assert.deepEqual(writes, [
    { method: 'DELETE', body: undefined },
    { method: 'PATCH', body: { status: 'inactive' } },
    { method: 'PATCH', body: { status: 'active' } },
  ])
})

test('rejects invalid IDs, pagination, and write data before making requests', async () => {
  const api = makeApi(() => assert.fail('must not fetch'))
  for (const id of [0, -1, 1.2, NaN, Infinity, Number.MAX_SAFE_INTEGER + 1, '../users']) {
    await assert.rejects(api.get(id))
    await assert.rejects(api.archive(id))
  }
  for (const page of [0, -1, NaN, 1.5]) await assert.rejects(api.list(page))
  await assert.rejects(api.create({ ...fields, name: ' ' }))
  await assert.rejects(api.update(12, { ...fields, status: 'archived' }))
  await assert.rejects(api.reactivate(12, 'archived'))
})

test('does not retry a failed or expired write', async () => {
  for (const status of [403, 404, 409, 419, 422]) {
    let writes = 0
    const api = makeApi(async (url) => {
      if (url.pathname === '/sanctum/csrf-cookie') return new Response(null, { status: 204 })
      writes++
      return Response.json({ errors: { name: ['Invalid project name.'] } }, { status })
    })
    await assert.rejects(api.update(12, fields), (error) => error.status === status)
    assert.equal(writes, 1)
  }
})

test('validates project form boundaries and preserves text without treating it as markup', () => {
  assert.equal(projectFormSchema.safeParse({ ...fields, name: ' ' }).success, false)
  assert.equal(projectFormSchema.safeParse({ ...fields, name: 'a'.repeat(256) }).success, false)
  assert.equal(projectFormSchema.safeParse({ ...fields, description: 'a'.repeat(10001) }).success, false)
  assert.equal(projectFormSchema.safeParse({ ...fields, name: 'a'.repeat(255), description: 'a'.repeat(10000) }).success, true)
  const text = '<script>alert(1)</script>'
  assert.equal(projectFormSchema.parse({ ...fields, description: text }).description, text)
})
