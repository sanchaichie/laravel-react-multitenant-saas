import type { ApiErrorBody } from '@shared/types/api'
import { ApiError, NetworkError } from './errors'
import { parseSseChunk, type SseEvent } from './sse'

// Calls from client components go to this app's /api proxy (same origin): it adds
// the token from the httpOnly cookie, so page scripts never hold it.

export interface ClientRequestOptions {
  method?: 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE'
  body?: unknown
  /** The organization the page was rendered for: X-Organization-Id, a precondition (409 when another tab switched). */
  organizationId?: number
  signal?: AbortSignal
  accept?: string
}

/**
 * What the page does when the session or the organization changed under it (the
 * caller passes the router's moves; this module stays free of React). Set once by
 * useClientApiEvents() in the app layout.
 */
export const clientApiEvents = {
  onUnauthenticated: () => window.location.assign(`/session-expired?redirect=${encodeURIComponent(window.location.pathname + window.location.search)}`),
  onOrganizationChanged: () => window.location.assign('/tasks'),
}

/** The successful Response, or ApiError / NetworkError (after handling 401 and 409). */
export async function clientRequest(path: string, { method = 'GET', body, organizationId, signal, accept = 'application/json' }: ClientRequestOptions = {}): Promise<Response> {
  const headers: Record<string, string> = { Accept: accept }
  if (body !== undefined) {
    headers['Content-Type'] = 'application/json'
  }
  if (organizationId !== undefined) {
    headers['X-Organization-Id'] = String(organizationId)
  }

  let response: Response
  try {
    response = await fetch(path, { method, headers, body: body === undefined ? undefined : JSON.stringify(body), signal })
  } catch (error) {
    // Cancelled by the caller (Stop, unmount): let them see it.
    if (signal?.aborted) {
      throw error
    }
    throw new NetworkError()
  }

  if (!response.ok) {
    const data = (await response.json().catch(() => null)) as ApiErrorBody | null
    if (response.status === 401) {
      clientApiEvents.onUnauthenticated()
    } else if (response.status === 409 && data?.code === 'organization_changed') {
      clientApiEvents.onOrganizationChanged()
    }
    throw new ApiError(response.status, data)
  }

  return response
}

/** clientRequest, with the JSON body parsed (undefined for 204). */
export async function clientFetch<T>(path: string, options: ClientRequestOptions = {}): Promise<T> {
  const response = await clientRequest(path, options)
  return response.status === 204 ? (undefined as T) : ((await response.json()) as T)
}

interface ClientStreamOptions extends Omit<ClientRequestOptions, 'method' | 'accept' | 'signal'> {
  signal: AbortSignal
  /** Each server-sent event as it arrives. */
  onEvent: (event: SseEvent) => void
}

/**
 * POST to an endpoint that answers with server-sent events (AI chat, writing help)
 * and hand each event to onEvent until the stream ends (the same contract as web's
 * apiStream). A refusal before streaming (402, 422, 429) throws ApiError; a dropped
 * connection after it started just ends; only an abort by the caller throws.
 */
export async function clientStream(path: string, { onEvent, signal, ...options }: ClientStreamOptions): Promise<void> {
  const response = await clientRequest(path, { ...options, method: 'POST', signal, accept: 'text/event-stream' })
  if (!response.body) {
    throw new Error('The answer had no body.')
  }

  const reader = response.body.getReader()
  const decoder = new TextDecoder()
  let rest = ''
  try {
    for (;;) {
      const { done, value } = await reader.read()
      if (done) break
      const parsed = parseSseChunk(rest, decoder.decode(value, { stream: true }))
      rest = parsed.rest
      parsed.events.forEach(onEvent)
    }
  } catch (error) {
    if (signal.aborted) throw error
    return
  }

  parseSseChunk(rest, decoder.decode() + '\n\n').events.forEach(onEvent)
}
