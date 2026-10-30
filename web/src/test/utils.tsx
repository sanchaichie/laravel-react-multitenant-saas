import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { createMemoryRouter } from 'react-router'
import { RouterProvider } from 'react-router/dom'
import { vi } from 'vitest'
import { AppProviders } from '@/components/AppProviders'
import { routes } from '@/router'
import { useAuth } from '@/stores/auth'
import type { Organization, OrganizationList, Role, User } from '@/types/api'

/** Acme's default roles (ids as the API would give them). */
export const ownerRole: Role = { id: 1, name: 'Owner', is_owner: true }
export const adminRole: Role = { id: 2, name: 'Admin', is_owner: false }
export const memberRole: Role = { id: 3, name: 'Member', is_owner: false }

export const acme: Organization = {
  id: 10,
  name: 'Acme',
  role: ownerRole,
  permissions: ['tasks.create', 'tasks.manage', 'members.invite', 'members.manage', 'roles.manage', 'audit_log.view'],
  created_at: '2026-10-01T12:00:00.000000Z',
}
export const beta: Organization = {
  id: 20,
  name: 'Beta',
  role: { id: 23, name: 'Member', is_owner: false },
  permissions: ['tasks.create'],
  created_at: '2026-10-02T12:00:00.000000Z',
}

export const ada: User = {
  id: 1,
  name: 'Ada Lovelace',
  email: 'ada@example.com',
  email_verified: true,
  current_organization: acme,
  created_at: '2026-10-01T12:00:00.000000Z',
}

/** GET /api/organizations as the API answers it. */
export function organizationList(organizations: Organization[], currentId: number | null = organizations[0]?.id ?? null): OrganizationList {
  return { data: organizations, meta: { current_organization_id: currentId } }
}

/** Logged in as `user`, as if bootstrapSession had run. */
export function logIn(user: User = ada, token = '1|test-token') {
  useAuth.setState({ token, user })
}

/** The app's real routes at `path`, in memory (no browser URL). */
export function renderApp(path: string) {
  const router = createMemoryRouter(routes, { initialEntries: [path] })
  render(
    <AppProviders>
      <RouterProvider router={router} />
    </AppProviders>,
  )

  return { router, user: userEvent.setup() }
}

export function jsonResponse(body: unknown, status = 200): Response {
  if (status === 204) {
    return new Response(null, { status })
  }

  return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } })
}

/** Gets the request's init, and its URL (for the query string). */
type Handler = (init: RequestInit, url: URL) => Response | Promise<Response>

/**
 * Replaces fetch: each "METHOD /path" (without the query string) gets its handler's
 * response; anything else fails the test. Returns the mock to inspect calls.
 */
export function mockApi(handlers: Record<string, Handler>) {
  const fetchMock = vi.fn(async (input: RequestInfo | URL, init: RequestInit = {}) => {
    const url = new URL(String(input))
    const key = `${init.method ?? 'GET'} ${url.pathname}`
    const handler = handlers[key]
    if (!handler) {
      throw new Error(`Unexpected request: ${key}`)
    }

    return handler(init, url)
  })
  vi.stubGlobal('fetch', fetchMock)

  return fetchMock
}

/** The JSON body a mocked request was sent with. */
export function sentBody(init: RequestInit): unknown {
  return JSON.parse(String(init.body))
}

/** Open the account menu in the sidebar and choose Log out. */
export async function logOutFromMenu(user: ReturnType<typeof userEvent.setup>) {
  await user.click(await screen.findByRole('button', { name: /^Account:/ }))
  await user.click(await screen.findByRole('menuitem', { name: 'Log out' }))
}
