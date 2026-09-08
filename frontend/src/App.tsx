import { RegistrationPage } from './features/auth/registration'
import { LoginPage, PasswordRecoveryPage } from './features/auth/login'
import { Navigate, Route, Routes } from 'react-router-dom'
import { useAuth } from './features/auth/model/AuthContext'
import { AccountPage } from './features/auth/ui/AccountPage'

function App() {
  const { user, status, error, retry } = useAuth()
  if (status === 'loading') return <p role="status" className="p-6">Loading your session…</p>
  if (status === 'error') return <main className="grid gap-4 p-6">
    <p role="alert">{error}</p><button onClick={retry}>Try again</button>
  </main>
  return (
    <Routes>
      <Route path="/login" element={user ? <Navigate to="/account" replace /> : <LoginPage />} />
      <Route path="/account" element={user ? <AccountPage /> : <Navigate to="/login" replace />} />
      <Route path="/register" element={<RegistrationPage />} />
      <Route path="/forgot-password" element={<PasswordRecoveryPage />} />
      <Route path="*" element={<Navigate to="/login" replace />} />
    </Routes>
  )
}

export default App
