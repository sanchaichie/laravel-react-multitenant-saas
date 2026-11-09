import { vi } from 'vitest'

/**
 * Stand-ins for next/headers and next/navigation in tests of Server Actions and
 * Server Components: a cookie jar, request headers, and redirect()/notFound() that
 * throw like the real ones (they end the function), so tests can expect them.
 */
export const cookieJar = new Map<string, string>()
export const requestHeaders = new Headers({ 'user-agent': 'Vitest Browser' })

export class RedirectError extends Error {
  constructor(readonly url: string) {
    super(`NEXT_REDIRECT ${url}`)
  }
}

export class NotFoundError extends Error {}

export const nextHeaders = {
  cookies: async () => ({
    get: (name: string) => (cookieJar.has(name) ? { name, value: cookieJar.get(name) } : undefined),
    set: (name: string, value: string) => void cookieJar.set(name, value),
    delete: (name: string) => void cookieJar.delete(name),
  }),
  headers: async () => requestHeaders,
}

export const nextNavigation = {
  redirect: (url: string) => {
    throw new RedirectError(url)
  },
  notFound: () => {
    throw new NotFoundError('NEXT_NOT_FOUND')
  },
}

export const nextCache = { revalidatePath: vi.fn() }

/** A fetch stub answering by "METHOD /path" (no query string); every other call fails the test. */
export function mockFetch(routes: Record<string, (init: RequestInit, url: URL) => Response | Promise<Response>>) {
  const calls: { method: string; url: URL; init: RequestInit }[] = []
  const fetchMock = vi.fn(async (input: string | URL | Request, init: RequestInit = {}) => {
    const url = new URL(typeof input === 'string' ? input : input instanceof URL ? input.href : input.url, 'http://localhost')
    const method = (init.method ?? 'GET').toUpperCase()
    calls.push({ method, url, init })
    const handler = routes[`${method} ${url.pathname}`]
    if (!handler) {
      throw new Error(`Unmocked request: ${method} ${url.pathname}`)
    }
    return handler(init, url)
  })
  vi.stubGlobal('fetch', fetchMock)

  return calls
}

export function json(body: unknown, status = 200): Response {
  return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } })
}

const encoder = new TextEncoder()

/** One server-sent event, as bytes on the wire. */
export const sseEvent = (name: string, data: unknown) => encoder.encode(`event: ${name}\ndata: ${JSON.stringify(data)}\n\n`)

/**
 * A streamed answer the test writes to by hand (send, close); it notes when the
 * request was aborted. Use `respond` as a mockFetch handler.
 */
export function manualStream() {
  let controller!: ReadableStreamDefaultController<Uint8Array>
  const handle = {
    aborted: false,
    send: (chunk: Uint8Array) => controller.enqueue(chunk),
    close: () => controller.close(),
  }
  const respond = (init: RequestInit) => {
    init.signal?.addEventListener('abort', () => {
      handle.aborted = true
      // Like a real fetch body: an abort errors the stream being read.
      try {
        controller.error(new DOMException('The operation was aborted.', 'AbortError'))
      } catch {
        // Already closed.
      }
    })
    const body = new ReadableStream<Uint8Array>({ start: (c) => void (controller = c) })
    return new Response(body, { status: 200, headers: { 'Content-Type': 'text/event-stream' } })
  }
  return { handle, respond }
}

/** A whole streamed answer at once: deltas, then done (or an error event). */
export function sseResponse(pieces: string[], end: { done: string } | { error: string } = { done: 'fake-model' }): Response {
  const chunks = [...pieces.map((text) => sseEvent('delta', { text })), 'done' in end ? sseEvent('done', { model: end.done }) : sseEvent('error', { message: end.error })]
  const body = new ReadableStream<Uint8Array>({
    start(controller) {
      chunks.forEach((chunk) => controller.enqueue(chunk))
      controller.close()
    },
  })
  return new Response(body, { status: 200, headers: { 'Content-Type': 'text/event-stream' } })
}
