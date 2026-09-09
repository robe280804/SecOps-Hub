import { z } from 'zod'
import { ApiError, type createApiClient } from '../../../shared/api/client.ts'
import { projectFormSchema, projectPageSchema, projectSchema, type ProjectFields } from '../model/projectSchema.ts'

type Client = ReturnType<typeof createApiClient>

function parse<T>(schema: z.ZodType<T>, response: unknown): T {
  const result = schema.safeParse(response)
  if (!result.success) throw new ApiError('The service returned an invalid project response.', 502)
  return result.data
}

function projectPath(id: number): string {
  if (!Number.isSafeInteger(id) || id <= 0) throw new ApiError('Invalid project ID.')
  return '/api/v1/projects/' + id
}

function payload(fields: ProjectFields) {
  const validated = projectFormSchema.parse(fields)
  return { ...validated, description: validated.description.trim() || null }
}

export function createProjectApi(client: Client) {
  const responseSchema = z.object({ data: projectSchema })
  return {
    async list(page = 1, signal?: AbortSignal) {
      if (!Number.isSafeInteger(page) || page <= 0) throw new ApiError('Invalid page number.')
      return parse(projectPageSchema, await client.request('/api/v1/projects', { query: { page }, signal }))
    },
    async get(id: number, signal?: AbortSignal) {
      return parse(responseSchema, await client.request(projectPath(id), { signal })).data
    },
    async create(fields: ProjectFields, signal?: AbortSignal) {
      const body = payload(fields)
      await client.csrf()
      return parse(responseSchema, await client.request('/api/v1/projects', { method: 'POST', body, signal })).data
    },
    async update(id: number, fields: ProjectFields, signal?: AbortSignal) {
      const path = projectPath(id)
      const body = payload(fields)
      await client.csrf()
      return parse(responseSchema, await client.request(path, { method: 'PATCH', body, signal })).data
    },
    async archive(id: number, signal?: AbortSignal): Promise<void> {
      const path = projectPath(id)
      await client.csrf()
      await client.request(path, { method: 'DELETE', signal })
    },
    async reactivate(id: number, status: 'inactive' | 'active', signal?: AbortSignal) {
      const path = projectPath(id)
      const body = { status: z.enum(['inactive', 'active']).parse(status) }
      await client.csrf()
      return parse(responseSchema, await client.request(path, { method: 'PATCH', body, signal })).data
    },
  }
}
