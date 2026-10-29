import type { FieldValues, Path, UseFormSetError } from 'react-hook-form'
import { ApiError, NetworkError } from '@/lib/api'

/**
 * Puts a Laravel 422's messages on the form's fields (the first message of each).
 * Returns false when there was nothing to put on a field, so the caller can show
 * errorMessage() instead.
 */
export function applyFieldErrors<T extends FieldValues>(
  error: unknown,
  setError: UseFormSetError<T>,
  fields: readonly Path<T>[],
): boolean {
  if (!(error instanceof ApiError) || error.status !== 422 || !error.data?.errors) {
    return false
  }

  let applied = false
  for (const field of fields) {
    const message = error.data.errors[field]?.[0]
    if (message) {
      // The first field with an error gets focus, like a client-side error.
      setError(field, { type: 'server', message }, { shouldFocus: !applied })
      applied = true
    }
  }

  return applied
}

/** The best message to show for a failed API call. */
export function errorMessage(error: unknown): string {
  if (error instanceof NetworkError) {
    return 'Cannot reach the server. Is the API running?'
  }
  if (error instanceof ApiError) {
    if (error.status === 429) {
      return 'Too many attempts. Wait a minute and try again.'
    }
    // A 500's message is exception text in debug mode, not something to show.
    if (error.status < 500 && error.data?.message) {
      return error.data.message
    }
  }

  return 'Something went wrong. Please try again.'
}
