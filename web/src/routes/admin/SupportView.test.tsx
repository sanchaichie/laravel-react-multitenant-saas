import { screen, waitFor, within } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { bootstrapSession } from '@/features/auth/session'
import { useAuth } from '@/stores/auth'
import { adminAda, makeAdminUserDetail } from '@/test/admin'
import { makeTask } from '@/test/tasks'
import { acme, beta, jsonResponse, mockApi, organizationList, renderApp } from '@/test/utils'
import type { User } from '@/types/api'

const REASON = 'Ticket 456: billing page looks empty'
const ADMIN_TOKEN = '1|admin-token'
const SESSION_TOKEN = '9|support-view'

/** Mia, as a support view sees her. */
const mia: User = {
  id: 7,
  name: 'Mia Chen',
  email: 'mia@example.com',
  email_verified: true,
  pending_email: null,
  two_factor_enabled: false,
  is_platform_admin: false,
  impersonated_by: { name: adminAda.name, expires_at: '2026-11-04T14:30:00.000000Z' },
  // Not the admin's own (Acme): switching back changes the current organization.
  current_organization: beta,
  created_at: '2026-10-01T09:00:00.000000Z',
}

type Handler = (init: RequestInit, url: URL) => Response

function bearer(init: RequestInit): string | undefined {
  return (init.headers as Record<string, string> | undefined)?.Authorization?.replace('Bearer ', '')
}

/** /api/me answers as whoever the token belongs to. */
function api(overrides: Record<string, Handler> = {}) {
  return mockApi({
    'GET /api/organizations': () => jsonResponse(organizationList([acme, beta])),
    'GET /api/me': (init) => jsonResponse({ data: bearer(init) === SESSION_TOKEN ? mia : adminAda }),
    'GET /api/admin/users/7': () => jsonResponse({ data: makeAdminUserDetail() }),
    'GET /api/tasks': () => jsonResponse({ data: [], links: {}, meta: { current_page: 1, last_page: 1, per_page: 20, total: 0, from: null, to: null } }),
    ...overrides,
  })
}

/** Already in a support view of Mia, the admin's token kept aside. */
function inSupportView() {
  useAuth.setState({ token: SESSION_TOKEN, adminToken: ADMIN_TOKEN, user: mia })
}

describe('view as user', () => {
  it("opens the app as the user, keeping the admin's token aside", async () => {
    useAuth.setState({ token: ADMIN_TOKEN, user: adminAda })
    const fetchMock = api({
      'POST /api/admin/users/7/impersonate': () => jsonResponse({ token: SESSION_TOKEN, expires_at: mia.impersonated_by?.expires_at }, 201),
    })

    const { router, user } = renderApp('/admin/users/7')
    await user.click(await screen.findByRole('button', { name: 'View as user' }))
    const dialog = await screen.findByRole('dialog')
    await user.type(within(dialog).getByLabelText('Reason'), REASON)
    await user.click(within(dialog).getByRole('button', { name: 'View as user' }))

    expect(await screen.findByText(/\(read-only\)/)).toHaveTextContent('Viewing as Mia Chen (read-only) · ends at 2:30 PM UTC')
    expect(router.state.location.pathname).toBe('/')
    expect(useAuth.getState()).toMatchObject({ token: SESSION_TOKEN, adminToken: ADMIN_TOKEN })
    expect(JSON.parse(localStorage.getItem('auth') ?? '{}').state).toEqual({ token: SESSION_TOKEN, adminToken: ADMIN_TOKEN })
    const me = fetchMock.mock.calls.find(([input, init]) => String(input).endsWith('/api/me') && bearer(init ?? {}) === SESSION_TOKEN)
    expect(me).toBeDefined()
    // No admin group while viewing as someone who isn't one.
    expect(screen.queryByText('Platform admin')).not.toBeInTheDocument()
  })

  it('stops viewing from the banner: back on the user\'s admin page as the admin, even from a task', async () => {
    inSupportView()
    const fetchMock = api({
      'POST /api/logout': () => jsonResponse(null, 204),
      'GET /api/tasks/42': () => jsonResponse({ data: makeTask({ id: 42, title: 'Launch plan', content_hidden: true }) }),
    })

    // A task page redirects to /tasks when the organization changes: Stop must still win.
    const { router, user } = renderApp('/tasks/42')
    await user.click(await screen.findByRole('button', { name: 'Stop viewing' }))

    expect(await screen.findByRole('heading', { level: 1, name: 'Mia Chen' })).toBeInTheDocument()
    expect(router.state.location.pathname).toBe('/admin/users/7')
    expect(screen.queryByText(/\(read-only\)/)).not.toBeInTheDocument()
    expect(useAuth.getState()).toMatchObject({ token: ADMIN_TOKEN, adminToken: null, user: adminAda })
    const logout = fetchMock.mock.calls.find(([input]) => String(input).endsWith('/api/logout'))
    expect(bearer(logout?.[1] ?? {})).toBe(SESSION_TOKEN)
  })

  it('keeps the admin\'s token when two ways back race (a 401 during Stop, two Stop buttons)', () => {
    inSupportView()

    useAuth.getState().endSupportView(adminAda)
    useAuth.getState().endSupportView(adminAda, { userId: 7, expired: true })

    expect(useAuth.getState()).toMatchObject({ token: ADMIN_TOKEN, adminToken: null, user: adminAda, supportViewEnded: null })
  })

  it('ends the session on the server only after swapping back, with its own token', async () => {
    inSupportView()
    const fetchMock = api({ 'POST /api/logout': () => jsonResponse(null, 204) })

    const { user } = renderApp('/tasks')
    await user.click(await screen.findByRole('button', { name: 'Stop viewing' }))

    await waitFor(() => expect(fetchMock.mock.calls.some(([input]) => String(input).endsWith('/api/logout'))).toBe(true))
    const urls = fetchMock.mock.calls.map(([input, init]) => `${new URL(String(input)).pathname} ${bearer(init ?? {})}`)
    expect(urls.indexOf(`/api/me ${ADMIN_TOKEN}`)).toBeLessThan(urls.indexOf(`/api/logout ${SESSION_TOKEN}`))
  })

  it('keeps the admin logged in when the API is down on Stop', async () => {
    inSupportView()
    api({
      'GET /api/me': (init) => (bearer(init) === ADMIN_TOKEN ? jsonResponse({ message: 'Server Error' }, 500) : jsonResponse({ data: mia })),
      'POST /api/logout': () => jsonResponse(null, 204),
    })

    const { user } = renderApp('/tasks')
    await user.click(await screen.findByRole('button', { name: 'Stop viewing' }))

    expect(await screen.findByRole('heading', { name: "Couldn't load your account" })).toBeInTheDocument()
    expect(useAuth.getState()).toMatchObject({ token: ADMIN_TOKEN, adminToken: null, user: null })
  })

  it('offers Stop viewing in place of Log out in the account menu', async () => {
    inSupportView()
    api()

    const { user } = renderApp('/tasks')
    await user.click(await screen.findByRole('button', { name: /^Account:/ }))

    expect(await screen.findByRole('menuitem', { name: 'Stop viewing' })).toBeInTheDocument()
    expect(screen.queryByRole('menuitem', { name: 'Log out' })).not.toBeInTheDocument()
  })

  it('goes back to the admin when the session ends on its own', async () => {
    inSupportView()
    // The session's token is refused (it expired); the admin's still works.
    api({
      'GET /api/tasks': (init) =>
        bearer(init) === SESSION_TOKEN ? jsonResponse({ message: 'Unauthenticated.' }, 401) : jsonResponse({ data: [], links: {}, meta: { current_page: 1, last_page: 1, per_page: 20, total: 0, from: null, to: null } }),
    })

    const { router } = renderApp('/tasks')

    expect(await screen.findByText('The support view ended.')).toBeInTheDocument()
    await waitFor(() => expect(router.state.location.pathname).toBe('/admin/users/7'))
    expect(useAuth.getState()).toMatchObject({ token: ADMIN_TOKEN, adminToken: null, user: adminAda })
  })

  it('logs out when the admin\'s own session is gone too', async () => {
    inSupportView()
    api({
      'GET /api/tasks': () => jsonResponse({ message: 'Unauthenticated.' }, 401),
      'GET /api/me': () => jsonResponse({ message: 'Unauthenticated.' }, 401),
    })

    const { router } = renderApp('/tasks')

    await waitFor(() => expect(router.state.location.pathname).toBe('/login'))
    expect(useAuth.getState()).toMatchObject({ token: null, adminToken: null, user: null })
  })

  it('reloads into the admin when the session expired while the tab was closed', async () => {
    useAuth.setState({ token: SESSION_TOKEN, adminToken: ADMIN_TOKEN, user: null })
    api({ 'GET /api/me': (init) => (bearer(init) === SESSION_TOKEN ? jsonResponse({ message: 'Unauthenticated.' }, 401) : jsonResponse({ data: adminAda })) })

    await bootstrapSession()

    // Only the tokens are saved, so the viewed user isn't known: nowhere to send the admin back to.
    expect(useAuth.getState()).toMatchObject({ token: ADMIN_TOKEN, adminToken: null, user: adminAda, supportViewEnded: null })
  })

  it('hides task content, the summary and the chat', async () => {
    inSupportView()
    api({ 'GET /api/tasks/42': () => jsonResponse({ data: makeTask({ id: 42, title: 'Launch plan', content_hidden: true }) }) })

    renderApp('/tasks/42')

    expect(await screen.findByText('Content hidden in the support view.')).toBeInTheDocument()
    expect(screen.getByRole('heading', { level: 1, name: 'Launch plan' })).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /chat/i })).not.toBeInTheDocument()
    // No summary request: mockApi would fail it (and the card would show an error).
    expect(screen.queryByText(/summary/i)).not.toBeInTheDocument()
  })
})
