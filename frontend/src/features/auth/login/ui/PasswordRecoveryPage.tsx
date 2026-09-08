import { Link } from 'react-router-dom'
import '../../ui/auth-form.css'

export function PasswordRecoveryPage() {
  return (
    <main className="auth-page">
      <section className="auth-card" aria-labelledby="recovery-title">
        <header>
          <h1 id="recovery-title">Reset your password</h1>
          <p>Password recovery will be implemented with the backend flow.</p>
        </header>
        <p className="auth-navigation">
          <Link to="/login">Return to sign in</Link>
        </p>
      </section>
    </main>
  )
}
