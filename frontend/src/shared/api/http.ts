import { createApiClient } from './client'

const configured = import.meta.env.VITE_API_ORIGIN || window.location.origin
const origin = new URL(configured)
if (origin.username || origin.password || origin.pathname !== '/' || origin.search || origin.hash ||
  !['http:', 'https:'].includes(origin.protocol) || (import.meta.env.PROD && origin.protocol !== 'https:')) {
  throw new Error('Configure VITE_API_ORIGIN as an HTTPS origin in production.')
}

const listeners = new Set<() => void>()
export function onSessionExpired(listener: () => void) {
  listeners.add(listener)
  return () => { listeners.delete(listener) }
}

export const http = createApiClient({
  origin: origin.origin,
  readCookie: () => document.cookie,
  onSessionExpired: () => listeners.forEach((listener) => listener()),
})
