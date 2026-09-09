import { RegistrationPage } from './features/auth/registration'
import { LoginPage, PasswordRecoveryPage } from './features/auth/login'
import { Navigate, NavLink, Outlet, Route, Routes } from 'react-router-dom'
import { useAuth } from './features/auth/model/AuthContext'
import { AccountPage } from './features/auth/ui/AccountPage'
import { CreateProjectPage, ProjectPage, ProjectsPage } from './features/projects'

function AuthenticatedLayout() {
  const { user } = useAuth()
  if (!user) return <Navigate to="/login" replace />
  return <div key={user.id}>
    <nav aria-label="Main navigation" className="flex gap-6 border-b bg-white px-6 py-4">
      <NavLink to="/account" className={({ isActive }) => isActive ? 'font-semibold underline' : 'text-blue-700'}>Account</NavLink>
      <NavLink to="/projects" className={({ isActive }) => isActive ? 'font-semibold underline' : 'text-blue-700'}>Projects</NavLink>
    </nav>
    <Outlet />
  </div>
}

function App() {
  const { user, status, error, retry } = useAuth()
  if (status === 'loading') return <p role="status" className="p-6">Loading your session…</p>
  if (status === 'error') return <main className="grid gap-4 p-6">
    <p role="alert">{error}</p><button onClick={retry}>Try again</button>
  </main>
  return (
    <Routes>
      <Route path="/login" element={user ? <Navigate to="/account" replace /> : <LoginPage />} />
      <Route element={<AuthenticatedLayout />}>
        <Route path="/account" element={<AccountPage />} />
        <Route path="/projects" element={<ProjectsPage />} />
        <Route path="/projects/new" element={<CreateProjectPage />} />
        <Route path="/projects/:projectId" element={<ProjectPage />} />
      </Route>
      <Route path="/register" element={<RegistrationPage />} />
      <Route path="/forgot-password" element={<PasswordRecoveryPage />} />
      <Route path="*" element={<Navigate to="/login" replace />} />
    </Routes>
  )
}

export default App
