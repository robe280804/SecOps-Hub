import { Link } from 'react-router-dom'

export function PasswordRecoveryPage() {
  return (
    <main className="grid min-h-svh place-items-center p-4">
      <section
        className="w-full max-w-md rounded-lg border border-gray-300 bg-white p-6 shadow-sm"
        aria-labelledby="recovery-title"
      >
        <header className="mb-6 space-y-2">
          <h1 id="recovery-title" className="text-2xl font-semibold text-gray-900">
            Reset your password
          </h1>
          <p className="text-sm text-gray-600">
            Password recovery will be implemented with the backend flow.
          </p>
        </header>
        <p className="text-center text-sm">
          <Link className="font-medium text-blue-700 hover:underline" to="/login">
            Return to sign in
          </Link>
        </p>
      </section>
    </main>
  )
}
