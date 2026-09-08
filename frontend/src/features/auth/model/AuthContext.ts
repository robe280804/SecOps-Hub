import { createContext, useContext } from 'react'
import type { AuthUser } from '../api/authApi'
import type { LoginFields } from '../login/model/loginSchema'

export type AuthState = {
  user: AuthUser | null
  status: 'loading' | 'ready' | 'error'
  error: string | null
  login: (credentials: LoginFields) => Promise<void>
  logout: () => Promise<void>
  retry: () => void
}
export const AuthContext = createContext<AuthState | null>(null)
export function useAuth(): AuthState {
  const context = useContext(AuthContext)
  if (!context) throw new Error('useAuth requires AuthProvider')
  return context
}
