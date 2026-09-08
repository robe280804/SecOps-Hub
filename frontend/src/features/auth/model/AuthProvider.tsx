import { useEffect, useState, type ReactNode } from 'react'
import { ApiError, errorMessage } from '../../../shared/api/client'
import { onSessionExpired } from '../../../shared/api/http'
import { authApi, type AuthUser } from '../api/authApi'
import { AuthContext, type AuthState } from './AuthContext'

export function AuthProvider({ children }: { children: ReactNode }) {
  const [user, setUser] = useState<AuthUser | null>(null)
  const [status, setStatus] = useState<AuthState['status']>('loading')
  const [error, setError] = useState<string | null>(null)
  const [attempt, setAttempt] = useState(0)

  useEffect(() => onSessionExpired(() => {
    setUser(null)
    setStatus('ready')
  }), [])

  useEffect(() => {
    const controller = new AbortController()
    authApi.me(controller.signal).then((currentUser) => {
      if (!controller.signal.aborted) { setUser(currentUser); setStatus('ready') }
    }).catch((failure: unknown) => {
      if (controller.signal.aborted) return
      if (failure instanceof ApiError && failure.status === 401) {
        setUser(null)
        setStatus('ready')
      } else {
        setError(errorMessage(failure))
        setStatus('error')
      }
    })
    return () => controller.abort()
  }, [attempt])

  return <AuthContext value={{
    user, status, error,
    async login(credentials) { setUser(await authApi.login(credentials)) },
    async logout() {
      try { await authApi.logout() } catch (failure) {
        if (!(failure instanceof ApiError && [401, 419].includes(failure.status))) throw failure
      }
      setUser(null)
    },
    retry() { setStatus('loading'); setError(null); setAttempt((value) => value + 1) },
  }}>{children}</AuthContext>
}
