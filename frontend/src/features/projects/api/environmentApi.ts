import { z } from 'zod'
import { ApiError, type createApiClient } from '../../../shared/api/client.ts'
import {
  environmentSchema, environmentPageSchema, environmentOptionsSchema, environmentWriteSchema, environmentUpdateSchema,
  type EnvironmentWrite, type EnvironmentUpdate,
} from '../model/environmentSchema.ts'

function positiveId(value: number): number {
  if (!Number.isSafeInteger(value) || value <= 0) throw new ApiError('Invalid environment request.')
  return value
}
function projectPath(projectId: number) { return '/api/v1/projects/' + positiveId(projectId) }
function parse<T>(schema: z.ZodType<T>, response: unknown): T {
  const result = schema.safeParse(response)
  if (!result.success) throw new ApiError('The service returned an invalid environment response.', 502)
  return result.data
}
function parseEnvironment(response: unknown, projectId: number, id?: number) {
  const { data } = parse(z.object({ data: environmentSchema }), response)
  if (data.project_id !== projectId || (id !== undefined && data.id !== id)) throw new ApiError('The service returned an unexpected environment.', 502)
  return data
}

export function createEnvironmentApi(client: ReturnType<typeof createApiClient>) {
  return {
    async list(projectId: number, page = 1, signal?: AbortSignal) {
      const result = parse(environmentPageSchema, await client.request(projectPath(projectId) + '/environments', {
        query: { page: positiveId(page) }, signal,
      }))
      if (result.data.some((environment) => environment.project_id !== projectId)) throw new ApiError('The service returned an unexpected environment.', 502)
      return result
    },
    async options(projectId: number, signal?: AbortSignal) {
      return parse(z.object({ data: environmentOptionsSchema }), await client.request(projectPath(projectId) + '/environment-options', { signal })).data
    },
    async get(projectId: number, id: number, signal?: AbortSignal) {
      return parseEnvironment(await client.request(projectPath(projectId) + '/environments/' + positiveId(id), { signal }), projectId, id)
    },
    async create(projectId: number, fields: EnvironmentWrite, signal?: AbortSignal) {
      const path = projectPath(projectId) + '/environments'
      const body = environmentWriteSchema.parse(fields)
      signal?.throwIfAborted()
      await client.csrf()
      signal?.throwIfAborted()
      return parseEnvironment(await client.request(path, { method: 'POST', body, signal }), projectId)
    },
    async update(projectId: number, id: number, fields: EnvironmentUpdate, signal?: AbortSignal) {
      const path = projectPath(projectId) + '/environments/' + positiveId(id)
      const body = environmentUpdateSchema.parse(fields)
      signal?.throwIfAborted()
      await client.csrf()
      signal?.throwIfAborted()
      return parseEnvironment(await client.request(path, { method: 'PATCH', body, signal }), projectId, id)
    },
    async remove(projectId: number, id: number, signal?: AbortSignal): Promise<void> {
      const path = projectPath(projectId) + '/environments/' + positiveId(id)
      signal?.throwIfAborted()
      await client.csrf()
      signal?.throwIfAborted()
      await client.request(path, { method: 'DELETE', signal })
    },
  }
}
