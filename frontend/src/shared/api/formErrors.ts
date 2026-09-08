import type { FieldValues, Path, UseFormSetError } from 'react-hook-form'
import { ApiError, errorMessage } from './client'

export function setApiFormErrors<T extends FieldValues>(
  failure: unknown,
  setError: UseFormSetError<T>,
  fields: readonly Path<T>[],
): void {
  let focused = false
  if (failure instanceof ApiError && failure.status === 422) {
    for (const field of fields) {
      const message = failure.errors[field]?.[0]
      if (message) {
        setError(field, { type: 'server', message }, { shouldFocus: !focused })
        focused = true
      }
    }
  }
  setError('root.server', { type: 'server', message: errorMessage(failure) })
}
