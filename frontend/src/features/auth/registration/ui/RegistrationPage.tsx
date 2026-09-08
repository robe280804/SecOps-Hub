import { zodResolver } from '@hookform/resolvers/zod'
import { useForm } from 'react-hook-form'
import { Link } from 'react-router-dom'
import {
  registrationSchema,
  type RegistrationFields,
} from '../model/registrationSchema'

export function RegistrationPage() {
  const {
    register,
    handleSubmit,
    formState: { errors, isSubmitting },
    setError,
  } = useForm<RegistrationFields>({
    resolver: zodResolver(registrationSchema),
    mode: 'onSubmit',
    reValidateMode: 'onChange',
    defaultValues: {
      name: '',
      email: '',
      password: '',
      passwordConfirmation: '',
    },
  })

  const submitRegistration = handleSubmit(() => {
    setError('root', {
      message: 'Registration is not available until the backend endpoint is enabled.',
    })
  })

  return (
    <main className="grid min-h-svh place-items-center p-4">
      <section
        className="w-full max-w-md rounded-lg border border-gray-300 bg-white p-6 shadow-sm"
        aria-labelledby="registration-title"
      >
        <header className="mb-6 space-y-2">
          <h1 id="registration-title" className="text-2xl font-semibold text-gray-900">
            Create your account
          </h1>
          <p className="text-sm text-gray-600">Enter your details to register for SecOps Hub.</p>
        </header>

        <form className="space-y-4" onSubmit={submitRegistration} noValidate>
          <div className="space-y-2">
            <label className="block text-sm font-medium text-gray-800" htmlFor="name">
              Name
            </label>
            <input
              className="min-h-11 w-full rounded-md border border-gray-400 px-3 py-2 text-base outline-none focus:border-blue-600 focus:ring-2 focus:ring-blue-600/20 aria-invalid:border-red-700"
              id="name"
              type="text"
              autoComplete="name"
              aria-invalid={Boolean(errors.name)}
              aria-describedby={errors.name ? 'name-error' : undefined}
              {...register('name')}
            />
            {errors.name && (
              <p id="name-error" className="text-sm text-red-700">
                {errors.name.message}
              </p>
            )}
          </div>

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
            <label className="block text-sm font-medium text-gray-800" htmlFor="password">
              Password
            </label>
            <input
              className="min-h-11 w-full rounded-md border border-gray-400 px-3 py-2 text-base outline-none focus:border-blue-600 focus:ring-2 focus:ring-blue-600/20 aria-invalid:border-red-700"
              id="password"
              type="password"
              autoComplete="new-password"
              aria-invalid={Boolean(errors.password)}
              aria-describedby={errors.password ? 'password-error' : undefined}
              {...register('password')}
            />
            {errors.password && (
              <p id="password-error" className="text-sm text-red-700">
                {errors.password.message}
              </p>
            )}
          </div>

          <div className="space-y-2">
            <label
              className="block text-sm font-medium text-gray-800"
              htmlFor="password-confirmation"
            >
              Confirm password
            </label>
            <input
              className="min-h-11 w-full rounded-md border border-gray-400 px-3 py-2 text-base outline-none focus:border-blue-600 focus:ring-2 focus:ring-blue-600/20 aria-invalid:border-red-700"
              id="password-confirmation"
              type="password"
              autoComplete="new-password"
              aria-invalid={Boolean(errors.passwordConfirmation)}
              aria-describedby={
                errors.passwordConfirmation ? 'password-confirmation-error' : undefined
              }
              {...register('passwordConfirmation')}
            />
            {errors.passwordConfirmation && (
              <p id="password-confirmation-error" className="text-sm text-red-700">
                {errors.passwordConfirmation.message}
              </p>
            )}
          </div>

          <button
            className="min-h-11 w-full rounded-md bg-gray-900 px-4 py-2 font-medium text-white hover:bg-gray-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600 disabled:cursor-wait disabled:opacity-60"
            type="submit"
            disabled={isSubmitting}
          >
            Create account
          </button>

          <p className="text-center text-sm text-gray-600">
            Already have an account?{' '}
            <Link className="font-medium text-blue-700 hover:underline" to="/login">
              Sign in
            </Link>
          </p>

          {errors.root && (
            <p className="text-sm text-red-700" role="alert">
              {errors.root.message}
            </p>
          )}
        </form>
      </section>
    </main>
  )
}
