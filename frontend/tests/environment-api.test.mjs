import { test } from 'node:test'
import assert from 'node:assert/strict'
import { createApiClient, ApiError } from '../src/shared/api/client.ts'
import { createEnvironmentApi } from '../src/features/projects/api/environmentApi.ts'
import {
  createEnvironmentFormSchema, environmentInitialValues, environmentPayload, environmentFormError, MIB, GIB,
} from '../src/features/projects/model/environmentForm.ts'

const network = { version: 1, mode: 'automatic', dns_servers: [], search_domains: [] }
const resources = { version: 1, cpus: 1, memory_bytes: 512 * MIB, storage_bytes: 5 * GIB, pids: 128 }
const options = {
  approved_images: ['registry.example.test/tools:approved'],
  max_per_project: 5, can_create: true,
  default_network_configuration: network, default_resource_limits: resources,
  min_resource_limits: { cpus: 0.1, memory_bytes: 64 * MIB, storage_bytes: GIB, pids: 16 },
  max_resource_limits: { cpus: 4, memory_bytes: 8192 * MIB, storage_bytes: 50 * GIB, pids: 512 },
  network_limits: { dns_servers: 3, search_domains: 6 },
}
const environment = {
  id: 8, project_id: 12, name: 'Recon', description: null,
  base_image: options.approved_images[0], desired_state: 'stopped', status: 'inactive',
  runtime_generation: 0, runtime_status: null, last_observed_at: null,
  network_configuration: network, resource_limits: resources,
  capabilities: { start: false, stop: false, shell: false, update: true, configure: true, delete: true },
  created_at: '2026-09-09T12:00:00Z', updated_at: '2026-09-09T12:00:00Z',
}
const makeApi = (fetcher) => createEnvironmentApi(createApiClient({
  origin: 'https://api.example.com', readCookie: () => 'XSRF-TOKEN=csrf', fetcher,
}))
const fields = () => ({ ...environmentInitialValues(options), name: 'Recon' })

test('requests a scoped terminal session after CSRF and rejects external terminal URLs', async () => {
  const expected = { url: '/terminal/' + 'a'.repeat(64) + '/', expires_at: '2026-09-14T12:15:00Z' }
  const calls = []
  const api = makeApi(async (url, init) => {
    calls.push(url.pathname)
    if (url.pathname === '/sanctum/csrf-cookie') return new Response(null, { status: 204 })
    assert.equal(init.method, 'POST')
    assert.equal(init.headers.get('X-XSRF-TOKEN'), 'csrf')
    return Response.json({ data: expected }, { status: 201 })
  })
  assert.deepEqual(await api.terminal(12, 8), expected)
  assert.deepEqual(calls, ['/sanctum/csrf-cookie', '/api/v1/projects/12/environments/8/terminal'])
  for (const url of ['https://evil.example/', '//evil.example/', '/terminal/../api/', 'javascript:alert(1)']) {
    const invalid = makeApi(async (path) => path.pathname === '/sanctum/csrf-cookie'
      ? new Response(null, { status: 204 }) : Response.json({ data: { ...expected, url } }))
    await assert.rejects(invalid.terminal(12, 8), (error) => error.status === 502)
  }
})

test('stops the scoped environment after CSRF and validates its response identity', async () => {
  const calls = []
  const api = makeApi(async (url, init) => {
    calls.push(url.pathname)
    if (url.pathname === '/sanctum/csrf-cookie') return new Response(null, { status: 204 })
    assert.equal(init.method, 'POST')
    return Response.json({ data: { ...environment, status: 'stopping' } }, { status: 202 })
  })
  assert.equal((await api.stop(12, 8)).status, 'stopping')
  assert.deepEqual(calls, ['/sanctum/csrf-cookie', '/api/v1/projects/12/environments/8/stop'])
})

test('starts the scoped environment after CSRF and accepts its asynchronous status', async () => {
  const calls = []
  const api = makeApi(async (url, init) => {
    calls.push(url.pathname)
    if (url.pathname === '/sanctum/csrf-cookie') return new Response(null, { status: 204 })
    assert.equal(init.method, 'POST')
    assert.equal(init.headers.get('X-XSRF-TOKEN'), 'csrf')
    return Response.json({ data: { ...environment, status: 'provisioning', desired_state: 'running' }, operation_id: 3 }, { status: 202 })
  })
  assert.equal((await api.start(12, 8)).status, 'provisioning')
  assert.deepEqual(calls, ['/sanctum/csrf-cookie', '/api/v1/projects/12/environments/8/start'])
})

test('does not replay failed starts and accepts running without terminal readiness', async () => {
  let writes = 0
  const api = makeApi(async (url) => {
    if (url.pathname === '/sanctum/csrf-cookie') return new Response(null, { status: 204 })
    writes++
    return Response.json({ message: 'Unavailable' }, { status: 503 })
  })
  await assert.rejects(api.start(12, 8), (error) => error.status === 503)
  assert.equal(writes, 1)
  const running = makeApi(async () => Response.json({ data: { ...environment, status: 'running', runtime_status: 'running' } }))
  assert.equal((await running.get(12, 8)).status, 'running')
})

test('lists environments with scoped pagination and reads live form options', async () => {
  const api = makeApi(async (url, init) => {
    assert.equal(init.credentials, 'include')
    assert.equal(init.cache, 'no-store')
    if (url.pathname.endsWith('environment-options')) return Response.json({ data: options })
    assert.equal(url.href, 'https://api.example.com/api/v1/projects/12/environments?page=2')
    return Response.json({ data: [environment], meta: { current_page: 2, last_page: 2, total: 16 }, links: { next: 'https://untrusted.test' } })
  })
  const result = await api.list(12, 2)
  assert.equal(result.data[0].id, 8)
  assert.equal('links' in result, false)
  assert.deepEqual(await api.options(12), options)
})

test('fetches the selected environment and strips internal fields', async () => {
  const api = makeApi(async (url) => {
    assert.equal(url.pathname, '/api/v1/projects/12/environments/8')
    return Response.json({ data: { ...environment, runtime_reference: 'private', last_error: 'secret' } })
  })
  assert.deepEqual(await api.get(12, 8), environment)
})

test('rejects mismatched projects, IDs, missing capabilities and malformed options', async () => {
  for (const data of [{ ...environment, project_id: 13 }, { ...environment, id: 9 }, { ...environment, capabilities: undefined }, { ...environment, status: 'unknown' }]) {
    await assert.rejects(makeApi(async () => Response.json({ data })).get(12, 8), (error) => error.status === 502)
  }
  await assert.rejects(makeApi(async () => Response.json({ data: [{ ...environment, project_id: 13 }], meta: { current_page: 1, last_page: 1, total: 1 } })).list(12), (error) => error.status === 502)
  await assert.rejects(makeApi(async () => Response.json({ data: { ...options, approved_images: null } })).options(12), (error) => error.status === 502)
})

test('creates only allowed fields after acquiring CSRF and sends numeric byte limits', async () => {
  const expected = { name: 'Recon', description: null, base_image: environment.base_image, network_configuration: network, resource_limits: resources }
  const calls = []
  const api = makeApi(async (url, init) => {
    calls.push(url.pathname)
    if (url.pathname === '/sanctum/csrf-cookie') return new Response(null, { status: 204 })
    assert.equal(init.method, 'POST')
    assert.equal(init.headers.get('X-XSRF-TOKEN'), 'csrf')
    assert.deepEqual(JSON.parse(init.body), expected)
    return Response.json({ data: environment }, { status: 201 })
  })
  await api.create(12, { ...environmentPayload(fields(), options, true), project_id: 99, status: 'ready', runtime_reference: 'container', id: 99 })
  assert.deepEqual(calls, ['/sanctum/csrf-cookie', '/api/v1/projects/12/environments'])
})

test('updates descriptive fields without resending protected runtime configuration', async () => {
  const writes = []
  const api = makeApi(async (url, init) => {
    if (url.pathname === '/sanctum/csrf-cookie') return new Response(null, { status: 204 })
    assert.equal(url.pathname, '/api/v1/projects/12/environments/8')
    writes.push([init.method, init.body && JSON.parse(init.body)])
    return init.method === 'DELETE' ? new Response(null, { status: 204 }) : Response.json({ data: environment })
  })
  const body = environmentPayload(fields(), options, false)
  await api.update(12, 8, { ...body, desired_state: 'deleted' })
  await api.remove(12, 8)
  assert.deepEqual(writes, [['PATCH', { name: 'Recon', description: null }], ['DELETE', undefined]])
})

test('rejects invalid route parameters before requests', async () => {
  const api = makeApi(() => assert.fail('must not fetch'))
  for (const id of [0, -1, 1.5, NaN, Infinity, '../users', Number.MAX_SAFE_INTEGER + 1]) {
    await assert.rejects(api.list(id))
    await assert.rejects(api.list(12, id))
    await assert.rejects(api.options(id))
    await assert.rejects(api.get(12, id))
    await assert.rejects(api.create(id, environmentPayload(fields(), options, true)))
    await assert.rejects(api.update(12, id, { name: 'Recon' }))
    await assert.rejects(api.remove(12, id))
  }
})

test('does not submit a mutation after cancellation during the CSRF request', async () => {
  for (const operation of ['create', 'update', 'remove']) {
    const controller = new AbortController()
    let requests = 0
    const api = makeApi(async (url) => {
      requests++
      assert.equal(url.pathname, '/sanctum/csrf-cookie')
      controller.abort()
      return new Response(null, { status: 204 })
    })
    const action = operation === 'create' ? api.create(12, environmentPayload(fields(), options, true), controller.signal)
      : operation === 'update' ? api.update(12, 8, { name: 'Recon' }, controller.signal) : api.remove(12, 8, controller.signal)
    await assert.rejects(action, (error) => error.name === 'AbortError')
    assert.equal(requests, 1)
  }
})

test('preserves validation and authorization errors without retrying mutations', async () => {
  for (const status of [403, 404, 409, 419, 422, 429]) {
    let writes = 0
    const api = makeApi(async (url) => {
      if (url.pathname === '/sanctum/csrf-cookie') return new Response(null, { status: 204 })
      writes++
      return Response.json({ errors: { name: ['An environment with this name already exists in this project.'] } }, { status })
    })
    await assert.rejects(api.create(12, environmentPayload(fields(), options, true)), (error) => error.status === status)
    assert.equal(writes, 1)
  }
})

test('uses configured defaults and converts fractional memory/storage values without rounding', () => {
  const custom = { ...options, default_resource_limits: { ...resources, memory_bytes: 536870913, storage_bytes: 5368709121 } }
  const data = { ...environmentInitialValues(custom), name: '  Recon  ', description: '  ', dns_servers: '1.1.1.1,\n2606:4700:4700::1111', search_domains: 'lab.example.test, internal' }
  const payload = environmentPayload(data, custom, true)
  assert.equal(payload.name, 'Recon')
  assert.equal(payload.description, null)
  assert.equal(payload.resource_limits.memory_bytes, 536870913)
  assert.equal(payload.resource_limits.storage_bytes, 5368709121)
  assert.deepEqual(payload.network_configuration.dns_servers, ['1.1.1.1', '2606:4700:4700::1111'])
  assert.deepEqual(payload.network_configuration.search_domains, ['lab.example.test', 'internal'])
})

test('validates form values against current limits, approved images and closed network rules', () => {
  const schema = createEnvironmentFormSchema(options, true)
  const invalid = [
    { name: '  ' }, { name: 'a'.repeat(256) }, { description: 'a'.repeat(10001) },
    { base_image: 'unapproved:latest' }, { cpus: 0 }, { cpus: 4.1 }, { cpus: NaN }, { cpus: Infinity },
    { memory_mib: 63 }, { memory_mib: 8193 }, { memory_mib: 512.00000001 },
    { storage_gib: 0 }, { storage_gib: 51 }, { pids: 0 }, { pids: 513 }, { pids: 128.5 },
    { dns_servers: 'dns.example.test' }, { dns_servers: '1.1.1.1,1.1.1.1' },
    { dns_servers: '1.1.1.1,2.2.2.2,3.3.3.3,4.4.4.4' },
    { search_domains: 'https://example.test' }, { search_domains: '*.example.test' },
    { search_domains: 'LAB.test,lab.test' }, { search_domains: '-invalid.test' },
    { search_domains: 'a b c d e f g' },
  ]
  for (const value of invalid) assert.equal(schema.safeParse({ ...fields(), ...value }).success, false, JSON.stringify(value))
  assert.equal(schema.safeParse(fields()).success, true)
  assert.equal(createEnvironmentFormSchema({ ...options, max_resource_limits: { ...options.max_resource_limits, cpus: 0.5 } }, true).safeParse(fields()).success, false)
})

test('allows descriptive edits to provisioned environments with a retired image', () => {
  const initial = environmentInitialValues(options, { ...environment, base_image: 'retired:latest', capabilities: { update: true, configure: false, delete: false } })
  assert.deepEqual(environmentPayload(initial, options, false), { name: 'Recon', description: null })
  assert.equal(createEnvironmentFormSchema(options, true).safeParse(initial).success, false)
})

test('maps nested server errors to visible form controls and explains uncertain saves', () => {
  const result = environmentFormError(new ApiError('Check fields', 422, {
    'network_configuration.dns_servers.1': ['Invalid address'],
    'resource_limits.memory_bytes': ['Memory limit exceeded'],
    name: ['Duplicate name'],
  }))
  assert.deepEqual(result.errors, { dns_servers: ['Invalid address'], memory_mib: ['Memory limit exceeded'], name: ['Duplicate name'] })
  assert.match(environmentFormError(new ApiError('Conflict', 409)).message, /Reload environments/)
  assert.match(environmentFormError(new ApiError('Timeout', 0)).message, /could not be confirmed/)
})
