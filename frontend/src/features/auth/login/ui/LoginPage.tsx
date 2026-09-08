import { zodResolver } from '@hookform/resolvers/zod'
import { useState } from 'react'
import { useForm } from 'react-hook-form'
import { Link } from 'react-router-dom'
import { loginSchema, type LoginFields } from '../model/loginSchema'
import '../../ui/auth-form.css'

export function LoginPage() {
  const [isPasswordVisible, setIsPasswordVisible] = useState(false)
  const {
    register,
    handleSubmit,
    formState: { errors, isSubmitting },
    setError,
  } = useForm<LoginFields>({
    resolver: zodResolver(loginSchema),
    mode: 'onSubmit',
    reValidateMode: 'onChange',
    defaultValues: { email: '', password: '' },
  })

  const submitLogin = handleSubmit(() => {
    setError('root', {
      message: 'Authentication submission will be connected to the API later.',
    })
  })

  return (
    <main className="auth-page">
      <section className="auth-card" aria-labelledby="login-title">
        <header>
          <h1 id="login-title">Sign in</h1>
          <p>Use your SecOps Hub account to continue.</p>
        </header>

        <form onSubmit={submitLogin} noValidate>
          <div className="form-field">
            <label htmlFor="email">Email</label>
            <input
              id="email"
              type="email"
              inputMode="email"
              autoComplete="email"
              spellCheck="false"
              aria-invalid={Boolean(errors.email)}
              aria-describedby={errors.email ? 'email-error' : undefined}
              {...register('email')}
            />
            {errors.email && (
              <p id="email-error" className="field-error">
                {errors.email.message}
              </p>
            )}
          </div>

          <div className="form-field">
            <div className="field-heading">
              <label htmlFor="password">Password</label>
              <Link to="/forgot-password">Forgot password?</Link>
            </div>
            <div className="password-input">
              <input
                id="password"
                type={isPasswordVisible ? 'text' : 'password'}
                autoComplete="current-password"
                aria-invalid={Boolean(errors.password)}
                aria-describedby={errors.password ? 'password-error' : undefined}
                {...register('password')}
              />
              <button
                type="button"
                className="password-toggle"
                aria-pressed={isPasswordVisible}
                aria-label={isPasswordVisible ? 'Hide password' : 'Show password'}
                onClick={() => setIsPasswordVisible((isVisible) => !isVisible)}
              >
                {isPasswordVisible ? 'Hide' : 'Show'}
              </button>
            </div>
            {errors.password && (
              <p id="password-error" className="field-error">
                {errors.password.message}
              </p>
            )}
          </div>

          <button type="submit" disabled={isSubmitting}>
            Sign in
          </button>

          {errors.root && (
            <p className="form-error" role="alert">
              {errors.root.message}
            </p>
          )}

          <p className="auth-navigation">
            Need an account? <Link to="/register">Create one</Link>
          </p>
        </form>
      </section>
    </main>
  )
}
