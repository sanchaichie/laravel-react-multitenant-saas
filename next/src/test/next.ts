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
    const url = new URL(typeof input === 'string' ? input : input instanceof URL ? input.href : input.url)
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
