import 'server-only'
import type { ApiErrorBody } from '@shared/types/api'
import { headers as requestHeaders } from 'next/headers'
import { clientIp } from './clientIp'
import { apiBaseUrl } from './config'
import { ApiError, NetworkError } from './errors'
import { getToken } from './session'

interface ServerApiOptions {
  method?: 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE'
  body?: unknown
  /**
   * The organization the page was showing when the user acted. Sent as
   * X-Organization-Id, a precondition only: the API answers 409
   * `organization_changed` if another tab switched meanwhile.
   */
  organizationId?: number
  /** A token other than the session's (right after login, before the cookie is read back). */
  token?: string | null
}

/**
 * The one way server code (pages, Server Actions) calls Laravel: adds the base
 * URL, JSON headers and the Bearer token from the session cookie. Returns the
 * parsed body (undefined for 204) or throws ApiError / NetworkError. Never cached:
 * every answer depends on who's asking.
 */
export async function serverApi<T>(path: string, options: ServerApiOptions = {}): Promise<T> {
  const { method = 'GET', body, organizationId } = options
  const token = options.token === undefined ? await getToken() : options.token
  const headers: Record<string, string> = { Accept: 'application/json' }
  if (body !== undefined) {
    headers['Content-Type'] = 'application/json'
  }
  if (token) {
    headers.Authorization = `Bearer ${token}`
  }
  // The browser's User-Agent, so the API names the device ("Chrome on macOS") in the
  // list of signed-in devices instead of seeing this server.
  // And the browser's IP (when a proxy in front reports it), so per-IP rate limits,
  // the device list and the audit log see the user, not this server.
  const incoming = await requestHeaders()
  const userAgent = incoming.get('user-agent')
  if (userAgent) {
    headers['User-Agent'] = userAgent
  }
  const ip = clientIp(incoming)
  if (ip) {
    headers['X-Forwarded-For'] = ip
  }
  if (organizationId !== undefined) {
    headers['X-Organization-Id'] = String(organizationId)
  }

  let response: Response
  try {
    response = await fetch(apiBaseUrl() + path, {
      method,
      headers,
      body: body === undefined ? undefined : JSON.stringify(body),
      cache: 'no-store',
      signal: AbortSignal.timeout(15_000),
    })
  } catch {
    throw new NetworkError()
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
