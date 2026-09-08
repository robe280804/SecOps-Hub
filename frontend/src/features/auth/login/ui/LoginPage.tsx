import { zodResolver } from '@hookform/resolvers/zod'
import { useState } from 'react'
import { useForm } from 'react-hook-form'
import { Link } from 'react-router-dom'
import { loginSchema, type LoginFields } from '../model/loginSchema'
import { useAuth } from '../../model/AuthContext'
import { setApiFormErrors } from '../../../../shared/api/formErrors'

export function LoginPage() {
  const { login } = useAuth()
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

  const submitLogin = handleSubmit(async (credentials) => {
    try {
      await login(credentials)
    } catch (failure) {
      setApiFormErrors(failure, setError, ['email', 'password'])
    }
  })

  return (
    <main className="grid min-h-svh place-items-center p-4">
      <section
        className="w-full max-w-md rounded-lg border border-gray-300 bg-white p-6 shadow-sm"
        aria-labelledby="login-title"
      >
        <header className="mb-6 space-y-2">
          <h1 id="login-title" className="text-2xl font-semibold text-gray-900">
            {isSubmitting ? 'Signing in…' : 'Sign in'}
          </h1>
          <p className="text-sm text-gray-600">Use your SecOps Hub account to continue.</p>
        </header>

        <form className="space-y-4" onSubmit={submitLogin} noValidate>
          <div className="space-y-2">
            <label className="block text-sm font-medium text-gray-800" htmlFor="email">
              Email
            </label>
            <input
              className="min-h-11 w-full rounded-md border border-gray-400 px-3 py-2 text-base outline-none focus:border-blue-600 focus:ring-2 focus:ring-blue-600/20 aria-invalid:border-red-700"
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
              <p id="email-error" className="text-sm text-red-700">
                {errors.email.message}
              </p>
            )}
          </div>

          <div className="space-y-2">
            <div className="flex items-center justify-between gap-4">
              <label className="text-sm font-medium text-gray-800" htmlFor="password">
                Password
              </label>
              <Link className="text-sm text-blue-700 hover:underline" to="/forgot-password">
                Forgot password?
              </Link>
            </div>
            <div className="relative flex">
              <input
                className="min-h-11 w-full rounded-md border border-gray-400 px-3 py-2 pr-18 text-base outline-none focus:border-blue-600 focus:ring-2 focus:ring-blue-600/20 aria-invalid:border-red-700"
                id="password"
                type={isPasswordVisible ? 'text' : 'password'}
                autoComplete="current-password"
                aria-invalid={Boolean(errors.password)}
                aria-describedby={errors.password ? 'password-error' : undefined}
                {...register('password')}
              />
              <button
                type="button"
                className="absolute inset-y-0 right-0 px-3 text-sm text-gray-700 hover:text-gray-950 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600"
                aria-pressed={isPasswordVisible}
                aria-label={isPasswordVisible ? 'Hide password' : 'Show password'}
                onClick={() => setIsPasswordVisible((isVisible) => !isVisible)}
              >
                {isPasswordVisible ? 'Hide' : 'Show'}
              </button>
            </div>
            {errors.password && (
              <p id="password-error" className="text-sm text-red-700">
                {errors.password.message}
              </p>
            )}
          </div>

          <button
            className="min-h-11 w-full rounded-md bg-gray-900 px-4 py-2 font-medium text-white hover:bg-gray-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600 disabled:cursor-wait disabled:opacity-60"
            type="submit"
            disabled={isSubmitting}
          >
            Sign in
          </button>

          {errors.root?.server && (
            <p className="text-sm text-red-700" role="alert">
              {errors.root.server.message}
            </p>
          )}

          <p className="text-center text-sm text-gray-600">
            Need an account?{' '}
            <Link className="font-medium text-blue-700 hover:underline" to="/register">
              Create one
            </Link>
          </p>
        </form>
      </section>
    </main>
  )
}
