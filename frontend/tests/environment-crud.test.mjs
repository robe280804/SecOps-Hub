import { test } from 'node:test'
import assert from 'node:assert/strict'
import { createApiClient } from '../src/shared/api/client.ts'
import { createEnvironmentApi } from '../src/features/projects/api/environmentApi.ts'

test('creates, reads, fully updates and deletes an environment through the scoped API', async () => {
  const fields = {
    name: 'Recon', description: null, base_image: 'tools:approved',
    network_configuration: { version: 1, mode: 'automatic', dns_servers: [], search_domains: [] },
    resource_limits: { version: 1, cpus: 1, memory_bytes: 536870912, storage_bytes: 5368709120, pids: 128 },
  }
  let saved = null
  const api = createEnvironmentApi(createApiClient({
    origin: 'https://api.example.com', readCookie: () => 'XSRF-TOKEN=csrf',
    fetcher: async (url, init) => {
      if (url.pathname === '/sanctum/csrf-cookie') return new Response(null, { status: 204 })
      assert.match(url.pathname, /^\/api\/v1\/projects\/12\/environments(?:\/8)?$/)
      if (init.method === 'POST') {
        saved = {
          ...JSON.parse(init.body), id: 8, project_id: 12,
          status: 'inactive', desired_state: 'stopped', runtime_generation: 0,
          runtime_status: null, last_observed_at: null,
          capabilities: { update: true, configure: true, delete: true },
          created_at: '2026-09-10T12:00:00Z', updated_at: '2026-09-10T12:00:00Z',
        }
        return Response.json({ data: saved }, { status: 201 })
      }
      if (init.method === 'PATCH') saved = { ...saved, ...JSON.parse(init.body) }
      if (init.method === 'DELETE') {
        saved = null
        return new Response(null, { status: 204 })
      }
      if (url.pathname.endsWith('/environments')) {
        return Response.json({ data: saved ? [saved] : [], meta: { current_page: 1, last_page: 1, total: saved ? 1 : 0 } })
      }
      return saved ? Response.json({ data: saved }) : Response.json({}, { status: 404 })
    },
  }))
  const created = await api.create(12, fields)
  assert.equal(created.status, 'inactive')
  assert.deepEqual(await api.get(12, created.id), created)
  assert.equal((await api.list(12)).meta.total, 1)
  const changes = {
    ...fields, name: 'Updated recon', description: 'DNS workspace', base_image: 'tools:v2',
    network_configuration: { ...fields.network_configuration, dns_servers: ['1.1.1.1'], search_domains: ['lab.test'] },
    resource_limits: { ...fields.resource_limits, cpus: 2, pids: 256 },
  }
  const updated = await api.update(12, created.id, changes)
  for (const key of Object.keys(changes)) assert.deepEqual(updated[key], changes[key])
  assert.deepEqual(await api.get(12, created.id), updated)
  await api.remove(12, created.id)
  assert.equal((await api.list(12)).meta.total, 0)
  await assert.rejects(api.get(12, created.id), (error) => error.status === 404)
})
