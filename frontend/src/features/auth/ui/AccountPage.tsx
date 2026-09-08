import { useState } from 'react'
import { useAuth } from '../model/AuthContext'
import { errorMessage } from '../../../shared/api/client'

export function AccountPage() {
  const { user, logout } = useAuth()
  const [pending, setPending] = useState(false)
  const [error, setError] = useState<string | null>(null)

  async function signOut() {
    setPending(true)
    setError(null)
    try { await logout() } catch (failure) { setError(errorMessage(failure)) }
    finally { setPending(false) }
  }

  return <main className="mx-auto grid max-w-md gap-4 p-6">
    <h1 className="text-2xl font-semibold">Welcome, {user?.name}</h1>
    <p>Signed in as {user?.email}</p>
    <button className="rounded bg-gray-900 p-3 text-white disabled:opacity-60" disabled={pending} onClick={signOut}>
      {pending ? 'Signing out…' : 'Sign out'}
    </button>
    {error && <p role="alert">{error}</p>}
  </main>
}
