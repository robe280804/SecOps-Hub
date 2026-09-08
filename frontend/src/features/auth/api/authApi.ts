import { z } from 'zod'
import { http } from '../../../shared/api/http'
import { ApiError } from '../../../shared/api/client'
import type { LoginFields } from '../login/model/loginSchema'

const userResponse = z.object({ data: z.object({
  id: z.number(), name: z.string(), email: z.string(), role: z.string().nullable(),
}) })
export type AuthUser = z.infer<typeof userResponse>['data']

function parseUser(response: unknown): AuthUser {
  const result = userResponse.safeParse(response)
  if (!result.success) throw new ApiError('The service returned an invalid response.', 502)
  return result.data.data
}

export const authApi = {
  async login(credentials: LoginFields): Promise<AuthUser> {
    await http.csrf()
    return parseUser(await http.request('/api/v1/session', { method: 'POST', body: credentials }))
  },
  async me(signal?: AbortSignal): Promise<AuthUser> {
    return parseUser(await http.request('/api/v1/me', { signal }))
  },
  async logout(): Promise<void> {
    await http.csrf()
    await http.request('/api/v1/session', { method: 'DELETE' })
  },
}
