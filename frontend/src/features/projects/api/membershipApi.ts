import { z } from 'zod'
import { ApiError, type createApiClient } from '../../../shared/api/client.ts'
import {
  accessLevelSchema, collaboratorFormSchema, collaboratorUserSchema,
  membershipPageSchema, membershipSchema, type AccessLevel,
} from '../model/membershipSchema.ts'

function positiveId(id: number): number {
  if (!Number.isSafeInteger(id) || id <= 0) throw new ApiError('Invalid collaborator request.')
  return id
}

function projectPath(projectId: number): string {
  return '/api/v1/projects/' + positiveId(projectId)
}

function parse<T>(schema: z.ZodType<T>, response: unknown): T {
  const result = schema.safeParse(response)
  if (!result.success) throw new ApiError('The service returned an invalid collaborator response.', 502)
  return result.data
}

export function createMembershipApi(client: ReturnType<typeof createApiClient>) {
  const responseSchema = z.object({ data: membershipSchema })
  return {
    async list(projectId: number, page = 1, signal?: AbortSignal) {
      return parse(membershipPageSchema, await client.request(projectPath(projectId) + '/memberships', {
        query: { page: positiveId(page) }, signal,
      }))
    },
    async lookup(projectId: number, email: string, signal?: AbortSignal) {
      const query = { email: collaboratorFormSchema.shape.email.parse(email) }
      return parse(z.object({ data: z.array(collaboratorUserSchema).max(1) }),
        await client.request(projectPath(projectId) + '/collaborator-lookup', { query, signal })).data
    },
    async add(projectId: number, userId: number, accessLevel: AccessLevel = 'viewer', signal?: AbortSignal) {
      const path = projectPath(projectId) + '/memberships'
      const body = { user_id: positiveId(userId), access_level: accessLevelSchema.parse(accessLevel) }
      await client.csrf()
      return parse(responseSchema, await client.request(path, { method: 'POST', body, signal })).data
    },
    async update(projectId: number, membershipId: number, accessLevel: AccessLevel, signal?: AbortSignal) {
      const path = projectPath(projectId) + '/memberships/' + positiveId(membershipId)
      const body = { access_level: accessLevelSchema.parse(accessLevel) }
      await client.csrf()
      return parse(responseSchema, await client.request(path, { method: 'PATCH', body, signal })).data
    },
    async remove(projectId: number, membershipId: number, signal?: AbortSignal): Promise<void> {
      const path = projectPath(projectId) + '/memberships/' + positiveId(membershipId)
      await client.csrf()
      await client.request(path, { method: 'DELETE', signal })
    },
  }
}
