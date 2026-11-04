import { screen, waitFor, within } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import {
  adminAda,
  adminPage,
  makeAdminOrganization,
  makeAdminOrganizationDetail,
  makeAdminUser,
  makeAdminUserDetail,
  makePlatformLogEntry,
  platformLogPage,
  twoFactorReauth,
  twoFactorRequired,
} from '@/test/admin'
import { acme, beta, jsonResponse, logIn, mockApi, organizationList, renderApp, sentBody } from '@/test/utils'

type Handler = (init: RequestInit, url: URL) => Response

function api(overrides: Record<string, Handler> = {}) {
  return mockApi({
    'GET /api/organizations': () => jsonResponse(organizationList([acme, beta])),
    ...overrides,
  })
}

function adminRequests(fetchMock: ReturnType<typeof api>) {
  return fetchMock.mock.calls.filter(([input]) => new URL(String(input)).pathname.startsWith('/api/admin'))
}

describe('platform admin area', () => {
  it('is "Page not found" for everyone else, without asking the API, and not in the sidebar', async () => {
    logIn()
    const fetchMock = api()

    renderApp('/admin/users')

    expect(await screen.findByRole('heading', { name: 'Page not found' })).toBeInTheDocument()
    expect(screen.queryByText('Platform admin')).not.toBeInTheDocument()
    expect(adminRequests(fetchMock)).toHaveLength(0)
  })

  it('shows the admin group in the sidebar for a platform admin', async () => {
    logIn(adminAda)
    api({ 'GET /api/admin/users': () => jsonResponse(adminPage([])) })

    renderApp('/admin/users')

    const nav = await screen.findByText('Platform admin')
    const group = nav.closest('[data-sidebar="group"]') as HTMLElement
    expect(within(group).getByRole('link', { name: 'Users' })).toHaveAttribute('aria-current', 'page')
    expect(within(group).getByRole('link', { name: 'Organizations' })).toBeInTheDocument()
    expect(within(group).getByRole('link', { name: 'Platform log' })).toBeInTheDocument()
  })

  it('lists users and searches through the URL', async () => {
    logIn(adminAda)
    const fetchMock = api({
      'GET /api/admin/users': (_init, url) =>
        url.searchParams.get('q') === 'mia'
          ? jsonResponse(adminPage([makeAdminUser()]))
          : jsonResponse(adminPage([makeAdminUser(), makeAdminUser({ id: 8, name: 'Sam Lee', email: 'sam@example.com', two_factor_enabled: true })])),
    })

    const { router, user } = renderApp('/admin/users')

    expect(await screen.findByRole('link', { name: 'Sam Lee' })).toHaveAttribute('href', '/admin/users/8')
    expect(screen.getByRole('link', { name: 'Mia Chen' })).toBeInTheDocument()

    await user.type(screen.getByRole('searchbox', { name: 'Search users' }), 'mia')

    await waitFor(() => expect(router.state.location.search).toBe('?q=mia'))
    await waitFor(() => expect(screen.queryByRole('link', { name: 'Sam Lee' })).not.toBeInTheDocument())
    expect(adminRequests(fetchMock).some(([input]) => new URL(String(input)).search === '?q=mia')).toBe(true)
  })

  it('says when no user matches', async () => {
    logIn(adminAda)
    api({ 'GET /api/admin/users': () => jsonResponse(adminPage([])) })

    renderApp('/admin/users?q=nobody')

    expect(await screen.findByRole('heading', { name: 'No users match' })).toBeInTheDocument()
    expect(screen.getByRole('searchbox', { name: 'Search users' })).toHaveValue('nobody')
  })

  it('asks an admin without two-factor to turn it on', async () => {
    logIn(adminAda)
    api({ 'GET /api/admin/users': () => jsonResponse(twoFactorRequired, 403) })

    renderApp('/admin/users')

    expect(await screen.findByRole('heading', { name: 'Turn on two-factor to use the admin area' })).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Account settings' })).toHaveAttribute('href', '/account')
  })

  it('asks for a fresh code, then loads the page', async () => {
    logIn(adminAda)
    let confirmed = false
    const fetchMock = api({
      'GET /api/admin/users': () => (confirmed ? jsonResponse(adminPage([makeAdminUser()])) : jsonResponse(twoFactorReauth, 403)),
      'POST /api/account/two-factor/reconfirm': () => {
        confirmed = true
        return jsonResponse(null, 204)
      },
    })

    const { user } = renderApp('/admin/users')

    expect(await screen.findByRole('heading', { name: "Confirm it's you" })).toBeInTheDocument()
    await user.type(screen.getByLabelText('Code from your authenticator app'), '123456')

    expect(await screen.findByRole('link', { name: 'Mia Chen' })).toBeInTheDocument()
    const reconfirm = fetchMock.mock.calls.find(([input]) => String(input).endsWith('/api/account/two-factor/reconfirm'))
    expect(sentBody(reconfirm?.[1] ?? {})).toEqual({ code: '123456' })
  })

  it('shows a wrong code under the boxes', async () => {
    logIn(adminAda)
    api({
      'GET /api/admin/users': () => jsonResponse(twoFactorReauth, 403),
      'POST /api/account/two-factor/reconfirm': () =>
        jsonResponse({ message: "That code isn't right.", errors: { code: ["That code isn't right. Check the time on your phone and try the newest code."] } }, 422),
    })

    const { user } = renderApp('/admin/users')

    await user.type(await screen.findByLabelText('Code from your authenticator app'), '000000')

    expect(await screen.findByText(/That code isn't right/)).toBeInTheDocument()
  })

  it('shows a user with their organizations and roles', async () => {
    logIn(adminAda)
    api({ 'GET /api/admin/users/7': () => jsonResponse({ data: makeAdminUserDetail() }) })

    renderApp('/admin/users/7')

    expect(await screen.findByRole('heading', { level: 1, name: 'Mia Chen' })).toBeInTheDocument()
    expect(screen.getByText('mia@example.com')).toBeInTheDocument()
    const beta = screen.getByRole('link', { name: 'Beta' })
    expect(beta).toHaveAttribute('href', '/admin/organizations/2')
    expect(within(beta.closest('tr') as HTMLElement).getByText('Member')).toBeInTheDocument()
    const acmeRow = screen.getByRole('link', { name: 'Acme' }).closest('tr') as HTMLElement
    expect(within(acmeRow).getByText('Owner')).toBeInTheDocument()
    expect(screen.getByText('Oct 20, 2026, 8:30 AM UTC')).toBeInTheDocument()
  })

  it('is "Page not found" for a missing user or a malformed id', async () => {
    logIn(adminAda)
    const fetchMock = api({ 'GET /api/admin/users/999': () => jsonResponse({ message: 'Not found.' }, 404) })

    renderApp('/admin/users/999')
    expect(await screen.findByRole('heading', { name: 'Page not found' })).toBeInTheDocument()
    expect(adminRequests(fetchMock)).toHaveLength(1)
  })

  it('makes no request for a malformed id', async () => {
    logIn(adminAda)
    const fetchMock = api()

    renderApp('/admin/users/abc')

    expect(await screen.findByRole('heading', { name: 'Page not found' })).toBeInTheDocument()
    expect(adminRequests(fetchMock)).toHaveLength(0)
  })

  it('lists organizations with their plan', async () => {
    logIn(adminAda)
    api({
      'GET /api/admin/organizations': () =>
        jsonResponse(adminPage([makeAdminOrganization({ id: 2, name: 'Beta', plan: 'pro', members_count: 9 }), makeAdminOrganization()])),
    })

    renderApp('/admin/organizations')

    const row = (await screen.findByRole('link', { name: 'Beta' })).closest('tr') as HTMLElement
    expect(within(row).getByText('pro')).toBeInTheDocument()
    expect(within(row).getByText('9')).toBeInTheDocument()
  })

  it("shows an organization's plan, budget, counts and members", async () => {
    logIn(adminAda)
    api({ 'GET /api/admin/organizations/1': () => jsonResponse({ data: makeAdminOrganizationDetail() }) })

    renderApp('/admin/organizations/1')

    expect(await screen.findByRole('heading', { level: 1, name: 'Acme' })).toBeInTheDocument()
    expect(screen.getByText('Never subscribed.')).toBeInTheDocument()
    expect(screen.getByText('$1.00 of $5.00 spent this month')).toBeInTheDocument()
    const main = screen.getByRole('main')
    expect(within(main).getByText('Tasks').nextElementSibling).toHaveTextContent('5')
    expect(screen.getByRole('link', { name: 'Sam Lee' })).toHaveAttribute('href', '/admin/users/8')
  })

  it('lists the platform log and loads more', async () => {
    logIn(adminAda)
    api({
      'GET /api/admin/audit-log': (_init, url) =>
        url.searchParams.get('cursor') === 'next'
          ? jsonResponse(platformLogPage([makePlatformLogEntry({ action: 'admin.granted', action_label: 'Made a platform admin', actor: null })]))
          : jsonResponse(platformLogPage([makePlatformLogEntry()], 'next')),
    })

    const { user } = renderApp('/admin/audit-log')

    expect(await screen.findByRole('link', { name: 'mia@example.com' })).toHaveAttribute('href', '/admin/users/7')
    await user.click(screen.getByRole('button', { name: 'Load more' }))

    expect(await screen.findByText('Console')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Load more' })).not.toBeInTheDocument()
  })
})
