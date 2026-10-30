import { toast } from 'sonner'
import { queryClient } from '@/lib/queryClient'
import { useAuth } from '@/stores/auth'
import type { ApiErrorBody, User } from '@/types/api'

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
  /** A token other than the session's: only for login, before the session is stored. */
  token?: string
}

/**
 * The one way to call the Laravel API (path like '/api/me'). Adds the base URL,
 * JSON headers and the Bearer token; returns the parsed body (undefined for 204)
 * or throws ApiError / NetworkError. Components don't call it directly: they use
 * the query hooks in features/<feature>/api.ts.
 *
 * A 401 for the current token ends the session. It doesn't navigate: RequireAuth
 * re-renders when the token goes and sends the user to /login.
 *
 * It also sends X-Organization-Id, the organization this tab thinks is current.
 * The server never uses it to choose one; org-scoped routes answer 409
 * `organization_changed` when another tab switched, and this tab then catches up.
 */
export async function apiFetch<T>(path: string, options: ApiFetchOptions = {}): Promise<T> {
  const response = await apiRequest(path, options)

  if (response.status === 204) {
    return undefined as T
  }

  return (await response.json()) as T
}

/**
 * apiFetch without reading the body: the successful Response, for callers that read
 * it themselves (apiStream). Same headers, 401 and 409 handling, and errors.
 */
export async function apiRequest(
  path: string,
  { method = 'GET', body, signal, endSessionOn401 = true, token: explicitToken, accept = 'application/json' }: ApiFetchOptions & { accept?: string } = {},
): Promise<Response> {
  const token = explicitToken ?? useAuth.getState().token
  const headers: Record<string, string> = { Accept: accept }
  if (body !== undefined) {
    headers['Content-Type'] = 'application/json'
  }
  if (token) {
    headers.Authorization = `Bearer ${token}`
  }
  const organizationId = explicitToken ? undefined : useAuth.getState().user?.current_organization?.id
  if (organizationId !== undefined) {
    headers['X-Organization-Id'] = String(organizationId)
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
    if (response.status === 409 && data?.code === 'organization_changed') {
      void catchUpWithOrganization()
    }
    throw new ApiError(response.status, data)
  }

  return response
}

let catchingUp: Promise<void> | null = null

/**
 * Another tab switched organization: load the user (and so the current
 * organization) again, and drop this tab's org-scoped data, which belongs to the
 * old one. Once at a time, however many requests got the 409.
 */
function catchUpWithOrganization(): Promise<void> {
  catchingUp ??= (async () => {
    try {
      const { data } = await apiFetch<{ data: User }>('/api/me')
      useAuth.getState().setUser(data)
      queryClient.removeQueries({ queryKey: ['org'] })
      void queryClient.invalidateQueries({ queryKey: ['organizations'] })
      toast.info('Your organization changed', {
        description: data.current_organization
          ? `You're now working in ${data.current_organization.name} (switched in another tab).`
          : 'It was changed in another tab.',
      })
    } catch {
      // A 401 already ended the session; anything else, the next request tries again.
    } finally {
      catchingUp = null
    }
  })()

  return catchingUp
}
