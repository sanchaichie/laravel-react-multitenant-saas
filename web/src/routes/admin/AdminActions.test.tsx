import { screen, waitFor, within } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { adminAda, makeAdminOrganizationDetail, makeAdminUserDetail, twoFactorReauth } from '@/test/admin'
import { acme, beta, jsonResponse, logIn, mockApi, organizationList, renderApp, sentBody } from '@/test/utils'

type Handler = (init: RequestInit, url: URL) => Response

const REASON = 'Ticket 123: lost their phone'

function api(overrides: Record<string, Handler> = {}) {
  return mockApi({
    'GET /api/organizations': () => jsonResponse(organizationList([acme, beta])),
    'GET /api/admin/users/7': () => jsonResponse({ data: makeAdminUserDetail({ two_factor_enabled: true }) }),
    ...overrides,
  })
}

function callsTo(fetchMock: ReturnType<typeof api>, path: string) {
  return fetchMock.mock.calls.filter(([input]) => new URL(String(input)).pathname === path)
}

describe('platform admin actions', () => {
  it('offers the actions that apply to a user', async () => {
    logIn(adminAda)
    api()

    renderApp('/admin/users/7')

    expect(await screen.findByRole('button', { name: 'Reset two-factor' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Sign out everywhere' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Suspend' })).toBeInTheDocument()
  })

  it('offers none for another platform admin, or for yourself', async () => {
    logIn(adminAda)
    api({
      'GET /api/admin/users/7': () => jsonResponse({ data: makeAdminUserDetail({ is_platform_admin: true }) }),
      [`GET /api/admin/users/${adminAda.id}`]: () => jsonResponse({ data: makeAdminUserDetail({ id: adminAda.id, is_platform_admin: true }) }),
    })

    const { router } = renderApp('/admin/users/7')
    expect(await screen.findByText(/managed from the command line/)).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Suspend' })).not.toBeInTheDocument()

    await router.navigate(`/admin/users/${adminAda.id}`)
    expect(await screen.findByText(/This is you/)).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Suspend' })).not.toBeInTheDocument()
  })

  it('needs a reason of at least 10 characters', async () => {
    logIn(adminAda)
    const fetchMock = api()

    const { user } = renderApp('/admin/users/7')
    await user.click(await screen.findByRole('button', { name: 'Suspend' }))
    const dialog = await screen.findByRole('dialog')
    await user.type(within(dialog).getByLabelText('Reason'), 'too short')
    await user.click(within(dialog).getByRole('button', { name: 'Suspend' }))

    expect(await within(dialog).findByText(/At least 10 characters/)).toBeInTheDocument()
    expect(callsTo(fetchMock, '/api/admin/users/7/suspend')).toHaveLength(0)
  })

  it('suspends with the reason and shows the user it answers with', async () => {
    logIn(adminAda)
    const fetchMock = api({
      'POST /api/admin/users/7/suspend': () =>
        jsonResponse({ data: makeAdminUserDetail({ suspended_at: '2026-11-04T10:00:00.000000Z', devices_count: 0 }) }),
    })

    const { user } = renderApp('/admin/users/7')
    await user.click(await screen.findByRole('button', { name: 'Suspend' }))
    const dialog = await screen.findByRole('dialog')
    await user.type(within(dialog).getByLabelText('Reason'), REASON)
    await user.click(within(dialog).getByRole('button', { name: 'Suspend' }))

    expect(await screen.findByText('The user is suspended.')).toBeInTheDocument()
    expect(screen.getByText('Suspended')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Unsuspend' })).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Sign out everywhere' })).not.toBeInTheDocument()
    expect(sentBody(callsTo(fetchMock, '/api/admin/users/7/suspend')[0][1] ?? {})).toEqual({ reason: REASON })
  })

  it('asks for a fresh code, then sends the same action again with the reason', async () => {
    logIn(adminAda)
    let confirmed = false
    const fetchMock = api({
      'POST /api/admin/users/7/two-factor/reset': () =>
        confirmed ? jsonResponse({ data: makeAdminUserDetail({ two_factor_enabled: false }) }) : jsonResponse(twoFactorReauth, 403),
      'POST /api/account/two-factor/reconfirm': () => {
        confirmed = true
        return jsonResponse(null, 204)
      },
    })

    const { user } = renderApp('/admin/users/7')
    await user.click(await screen.findByRole('button', { name: 'Reset two-factor' }))
    const dialog = await screen.findByRole('dialog')
    await user.type(within(dialog).getByLabelText('Reason'), REASON)
    await user.click(within(dialog).getByRole('button', { name: 'Reset two-factor' }))

    await user.type(await within(dialog).findByLabelText('Code from your authenticator app'), '123456')

    expect(await screen.findByText('Two-factor was reset.')).toBeInTheDocument()
    const resets = callsTo(fetchMock, '/api/admin/users/7/two-factor/reset')
    expect(resets).toHaveLength(2)
    expect(sentBody(resets[1][1] ?? {})).toEqual({ reason: REASON })
    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument())
    expect(screen.queryByRole('button', { name: 'Reset two-factor' })).not.toBeInTheDocument()
  })

  it("shows the API's refusal above the buttons", async () => {
    logIn(adminAda)
    api({
      'POST /api/admin/users/7/suspend': () =>
        jsonResponse({ message: 'This user is already suspended.', errors: { user: ['This user is already suspended.'] } }, 422),
    })

    const { user } = renderApp('/admin/users/7')
    await user.click(await screen.findByRole('button', { name: 'Suspend' }))
    const dialog = await screen.findByRole('dialog')
    await user.type(within(dialog).getByLabelText('Reason'), REASON)
    await user.click(within(dialog).getByRole('button', { name: 'Suspend' }))

    expect(await within(dialog).findByRole('alert')).toHaveTextContent('This user is already suspended.')
  })

  it('makes a member Owner', async () => {
    logIn(adminAda)
    const before = makeAdminOrganizationDetail()
    const after = makeAdminOrganizationDetail({
      members: before.members.map((member) => (member.id === 8 ? { ...member, role: { id: 1, name: 'Owner', is_owner: true } } : member)),
    })
    const fetchMock = api({
      'GET /api/admin/organizations/1': () => jsonResponse({ data: before }),
      'POST /api/admin/organizations/1/owners': () => jsonResponse({ data: after }),
    })

    const { user } = renderApp('/admin/organizations/1')
    const row = (await screen.findByRole('link', { name: 'Sam Lee' })).closest('tr') as HTMLElement
    // Mia is already Owner: no button on her row.
    const miaRow = screen.getByRole('link', { name: 'Mia Chen' }).closest('tr') as HTMLElement
    expect(within(miaRow).queryByRole('button', { name: 'Make Owner' })).not.toBeInTheDocument()

    await user.click(within(row).getByRole('button', { name: 'Make Owner' }))
    const dialog = await screen.findByRole('dialog')
    await user.type(within(dialog).getByLabelText('Reason'), REASON)
    await user.click(within(dialog).getByRole('button', { name: 'Make Owner' }))

    expect(await screen.findByText('Sam Lee is now an Owner.')).toBeInTheDocument()
    expect(within(row).getByText('Owner')).toBeInTheDocument()
    expect(sentBody(callsTo(fetchMock, '/api/admin/organizations/1/owners')[0][1] ?? {})).toEqual({ user_id: 8, reason: REASON })
  })
})
