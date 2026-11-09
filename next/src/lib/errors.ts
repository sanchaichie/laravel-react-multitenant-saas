import type { ApiErrorBody } from '@shared/types/api'

/** The API answered with an error status (422 validation, 403, 404, 409, 429, ...). */
export class ApiError extends Error {
  readonly status: number
  readonly data: ApiErrorBody | null

  constructor(status: number, data: ApiErrorBody | null) {
    super(data?.message ?? `Request failed with status ${status}`)
    this.name = 'ApiError'
    this.status = status
    this.data = data
  }
}

/** The API couldn't be reached at all (down, or not answering in time). */
export class NetworkError extends Error {
  constructor() {
    super('Cannot reach the server.')
    this.name = 'NetworkError'
  }
}

/** The best message to show for a failed API call (the same rules as web/src/lib/apiErrors.ts). */
export function errorMessage(error: unknown): string {
  if (error instanceof NetworkError) {
    return 'Cannot reach the server. Is the API running?'
  }
  if (error instanceof ApiError) {
    if (error.status === 403 && error.data?.code === 'email_unverified') {
      return 'Verify your email address first: use the link we emailed you.'
    }
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

/** A Laravel 422's first message per field, for the fields a form shows. */
export function fieldErrors(error: unknown, fields: readonly string[]): Record<string, string> {
  if (!(error instanceof ApiError) || error.status !== 422 || !error.data?.errors) {
    return {}
  }

  const result: Record<string, string> = {}
  for (const field of fields) {
    const message = error.data.errors[field]?.[0]
    if (message) {
      result[field] = message
    }
  }

  return result
}
