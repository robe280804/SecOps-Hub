import { zodResolver } from '@hookform/resolvers/zod'
import { useForm } from 'react-hook-form'
import { Link } from 'react-router-dom'
import {
  registrationSchema,
  type RegistrationFields,
} from '../model/registrationSchema'
import '../../ui/auth-form.css'

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
    <main className="auth-page">
      <section className="auth-card" aria-labelledby="registration-title">
        <header>
          <h1 id="registration-title">Create your account</h1>
          <p>Enter your details to register for SecOps Hub.</p>
        </header>

        <form onSubmit={submitRegistration} noValidate>
          <div className="form-field">
            <label htmlFor="name">Name</label>
            <input
              id="name"
              type="text"
              autoComplete="name"
              aria-invalid={Boolean(errors.name)}
              aria-describedby={errors.name ? 'name-error' : undefined}
              {...register('name')}
            />
            {errors.name && (
              <p id="name-error" className="field-error">
                {errors.name.message}
              </p>
            )}
          </div>

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
            <label htmlFor="password">Password</label>
            <input
              id="password"
              type="password"
              autoComplete="new-password"
              aria-invalid={Boolean(errors.password)}
              aria-describedby={errors.password ? 'password-error' : undefined}
              {...register('password')}
            />
            {errors.password && (
              <p id="password-error" className="field-error">
                {errors.password.message}
              </p>
            )}
          </div>

          <div className="form-field">
            <label htmlFor="password-confirmation">Confirm password</label>
            <input
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
              <p id="password-confirmation-error" className="field-error">
                {errors.passwordConfirmation.message}
              </p>
            )}
          </div>

          <button type="submit" disabled={isSubmitting}>
            Create account
          </button>

          <p className="auth-navigation">
            Already have an account? <Link to="/login">Sign in</Link>
          </p>

          {errors.root && (
            <p className="form-error" role="alert">
              {errors.root.message}
            </p>
          )}
        </form>
      </section>
    </main>
  )
}
