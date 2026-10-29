import { useAuth } from '@/stores/auth'
import type { ApiErrorBody } from '@/types/api'

// Baked in at build time (Vite), so it's public: never put a secret in VITE_*.
const API_BASE = (import.meta.env.VITE_API_BASE || 'http://localhost:8100').replace(/\/$/, '')

/** The API answered with an error status (422 validation, 403, 429, ...). */
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

/** The API couldn't be reached at all (down, offline, CORS refused). */
export class NetworkError extends Error {
  constructor() {
    super('Cannot reach the server.')
    this.name = 'NetworkError'
  }
}

interface ApiFetchOptions {
  method?: 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE'
  body?: unknown
  /** Cancels the request; a timeout (AbortSignal.timeout) counts as a NetworkError. */
  signal?: AbortSignal
  /** false: a 401 is left to the caller (logout ends the session itself). */
  endSessionOn401?: boolean
}

/**
 * The one way to call the Laravel API (path like '/api/me'). Adds the base URL,
 * JSON headers and the Bearer token; returns the parsed body (undefined for 204)
 * or throws ApiError / NetworkError. Components don't call it directly: they use
 * the query hooks in features/<feature>/api.ts.
 *
 * A 401 for the current token ends the session. It doesn't navigate: RequireAuth
 * re-renders when the token goes and sends the user to /login.
 */
export async function apiFetch<T>(
  path: string,
  { method = 'GET', body, signal, endSessionOn401 = true }: ApiFetchOptions = {},
): Promise<T> {
  const token = useAuth.getState().token
  const headers: Record<string, string> = { Accept: 'application/json' }
  if (body !== undefined) {
    headers['Content-Type'] = 'application/json'
  }
  if (token) {
    headers.Authorization = `Bearer ${token}`
  }

  let response: Response
  try {
    response = await fetch(API_BASE + path, {
      method,
      headers,
      body: body === undefined ? undefined : JSON.stringify(body),
      signal,
    })
  } catch (error) {
    // Cancelled by the caller: let them see it. A timeout is the API not answering.
    // (By name: the reason's DOMException class can differ between environments.)
    if (signal?.aborted && !(signal.reason instanceof Error && signal.reason.name === 'TimeoutError')) {
      throw error
    }
    throw new NetworkError()
  }

  // Only if the token is still the one we sent: a newer login must not be undone.
  if (endSessionOn401 && response.status === 401 && token !== null && useAuth.getState().token === token) {
    useAuth.getState().clearSession()
  }

  if (!response.ok) {
    const data = (await response.json().catch(() => null)) as ApiErrorBody | null
    throw new ApiError(response.status, data)
  }

  if (response.status === 204) {
    return undefined as T
  }

  return (await response.json()) as T
}
