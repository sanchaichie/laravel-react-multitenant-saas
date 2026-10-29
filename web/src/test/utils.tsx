import { QueryClientProvider } from '@tanstack/react-query'
import { render } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { createMemoryRouter } from 'react-router'
import { RouterProvider } from 'react-router/dom'
import { Toaster } from 'sonner'
import { vi } from 'vitest'
import { queryClient } from '@/lib/queryClient'
import { routes } from '@/router'
import { useAuth } from '@/stores/auth'
import type { User } from '@/types/api'

export const ada: User = {
  id: 1,
  name: 'Ada Lovelace',
  email: 'ada@example.com',
  created_at: '2026-10-01T12:00:00.000000Z',
}

/** Logged in as `user`, as if bootstrapSession had run. */
export function logIn(user: User = ada, token = '1|test-token') {
  useAuth.setState({ token, user })
}

/** The app's real routes at `path`, in memory (no browser URL). */
export function renderApp(path: string) {
  const router = createMemoryRouter(routes, { initialEntries: [path] })
  render(
    <QueryClientProvider client={queryClient}>
      <RouterProvider router={router} />
      <Toaster />
    </QueryClientProvider>,
  )

  return { router, user: userEvent.setup() }
}

export function jsonResponse(body: unknown, status = 200): Response {
  if (status === 204) {
    return new Response(null, { status })
  }

  return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } })
}

type Handler = (init: RequestInit) => Response | Promise<Response>

/**
 * Replaces fetch: each "METHOD /path" gets its handler's response; anything else
 * fails the test. Returns the mock to inspect calls.
 */
export function mockApi(handlers: Record<string, Handler>) {
  const fetchMock = vi.fn(async (input: RequestInfo | URL, init: RequestInit = {}) => {
    const url = new URL(String(input))
    const key = `${init.method ?? 'GET'} ${url.pathname}`
    const handler = handlers[key]
    if (!handler) {
      throw new Error(`Unexpected request: ${key}`)
    }

    return handler(init)
  })
  vi.stubGlobal('fetch', fetchMock)

  return fetchMock
}

/** The JSON body a mocked request was sent with. */
export function sentBody(init: RequestInit): unknown {
  return JSON.parse(String(init.body))
}
