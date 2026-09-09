export class ApiError extends Error {
  readonly status: number
  readonly errors: Record<string, string[]>
  readonly retryAfter: string | null

  constructor(message: string, status = 0, errors: Record<string, string[]> = {}, retryAfter: string | null = null) {
    super(message)
    this.name = 'ApiError'
    this.status = status
    this.errors = errors
    this.retryAfter = retryAfter
  }
}

export function errorMessage(error: unknown): string {
  return error instanceof ApiError ? error.message : 'Something went wrong. Please try again.'
}

type RequestOptions = {
  method?: 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE'
  body?: unknown
  signal?: AbortSignal
  query?: Record<string, string | number>
}

type ClientOptions = {
  origin: string
  fetcher?: typeof fetch
  readCookie: () => string
  onSessionExpired?: () => void
  timeoutMs?: number
}

function fieldErrors(body: unknown): Record<string, string[]> {
  if (!body || typeof body !== 'object' || !('errors' in body)) return {}
  const errors = body.errors
  if (!errors || typeof errors !== 'object') return {}
  return Object.fromEntries(Object.entries(errors).filter(
    ([, value]) => Array.isArray(value) && value.every((item) => typeof item === 'string'),
  ))
}

// Responses remain unknown until a feature validates its own API contract.
export function createApiClient({ origin, fetcher = fetch, readCookie, onSessionExpired, timeoutMs = 15000 }: ClientOptions) {
  async function request(path: string, options: RequestOptions = {}): Promise<unknown> {
    if (!/^\/(api\/v1\/|sanctum\/csrf-cookie$)/.test(path) || /[\\?#]/.test(path)) {
      throw new ApiError('Invalid API path.')
    }
    const url = new URL(path, origin)
    if (url.origin !== new URL(origin).origin || !/^\/(api\/v1\/|sanctum\/csrf-cookie$)/.test(url.pathname)) {
      throw new ApiError('Invalid API path.')
    }
    for (const [key, value] of Object.entries(options.query ?? {})) {
      url.searchParams.set(key, String(value))
    }
    const method = options.method ?? 'GET'
    const headers = new Headers({ Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' })
    if (options.body !== undefined) headers.set('Content-Type', 'application/json')
    if (method !== 'GET') {
      const cookie = readCookie().split(';').map((value) => value.trim()).find((value) => value.startsWith('XSRF-TOKEN='))
      let token = ''
      try { token = cookie ? decodeURIComponent(cookie.slice('XSRF-TOKEN='.length)) : '' } catch { /* Treat a malformed cookie as an expired session. */ }
      if (!token) {
        onSessionExpired?.()
        throw new ApiError('Your session has expired. Please sign in again.', 419)
      }
      headers.set('X-XSRF-TOKEN', token)
    }
    const timeout = AbortSignal.timeout(timeoutMs)
    const signal = options.signal ? AbortSignal.any([options.signal, timeout]) : timeout
    try {
      const response = await fetcher(url, {
        method, headers, credentials: 'include', cache: 'no-store', redirect: 'error', signal,
        body: options.body === undefined ? undefined : JSON.stringify(options.body),
      })
      const json = response.headers.get('content-type')?.includes('application/json')
      const body: unknown = json ? await response.json().catch(() => null) : null
      if (!response.ok) {
        if (response.status === 401 || response.status === 419) onSessionExpired?.()
        const messages: Record<number, string> = {
          401: 'Please sign in to continue.',
          403: 'You do not have permission to perform this action.',
          404: 'The requested resource was not found.',
          409: 'The project state has changed or it is archived. Reload the project before making changes.',
          419: 'Your session has expired. Please sign in again.',
          422: 'Please check the highlighted fields.',
          429: 'Too many requests. Please wait before trying again.',
        }
        throw new ApiError(messages[response.status] ?? 'The service is unavailable. Please try again later.',
          response.status, response.status === 422 ? fieldErrors(body) : {}, response.headers.get('Retry-After'))
      }
      if (response.status === 204) return undefined
      if (!json || body === null) throw new ApiError('The service returned an invalid response.', 502)
      return body
    } catch (error) {
      if (error instanceof ApiError || options.signal?.aborted) throw error
      throw new ApiError(timeout.aborted ? 'The request timed out. Please try again.' : 'Unable to connect. Check your connection and try again.')
    }
  }

  return { request, csrf: () => request('/sanctum/csrf-cookie') }
}
