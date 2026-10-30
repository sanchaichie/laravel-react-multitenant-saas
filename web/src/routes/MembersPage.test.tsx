import { screen, waitFor, within } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { queryClient } from '@/lib/queryClient'
import { useAuth } from '@/stores/auth'
import { acme, ada, adminRole, beta, memberRole, ownerRole, jsonResponse, logIn, mockApi, organizationList, renderApp, sentBody } from '@/test/utils'
import type { Member } from '@/types/api'

const me: Member = {
  id: ada.id,
  name: 'Ada Lovelace',
  email: 'ada@example.com',
  role: ownerRole,
  joined_at: '2026-10-01T12:00:00.000000Z',
  is_me: true,
  assignable_roles: [adminRole, memberRole],
  can: { update: true, delete: true },
}
const ana: Member = {
  id: 2,
  name: 'Ana Admin',
  email: 'ana@example.com',
  role: adminRole,
  joined_at: '2026-10-02T12:00:00.000000Z',
  is_me: false,
  assignable_roles: [ownerRole, memberRole],
  can: { update: true, delete: true },
}
const ownerSeenByMember: Member = { ...me, id: 3, name: 'Olga Owner', is_me: false, assignable_roles: [], can: { update: false, delete: false } }

function api(overrides: Record<string, (init: RequestInit) => Response> = {}) {
  return mockApi({
    'GET /api/organizations': () => jsonResponse(organizationList([acme, beta])),
    'GET /api/members': () => jsonResponse({ data: [ana, me] }),
    'GET /api/invitations': () => jsonResponse({ data: [], meta: { invitable_roles: [ownerRole, adminRole, memberRole], default_role_id: memberRole.id } }),
    ...overrides,
  })
}

describe('MembersPage', () => {
  it('lists the members with their roles', async () => {
    logIn()
    api()
    renderApp('/members')

    expect(await screen.findByRole('heading', { name: 'Members', level: 1 })).toBeInTheDocument()
    const rows = await screen.findAllByRole('row')
    expect(rows).toHaveLength(3) // header + 2
    expect(within(rows[1]).getByText('Ana Admin')).toBeInTheDocument()
    expect(within(rows[1]).getByRole('combobox', { name: 'Role of Ana Admin' })).toHaveTextContent('Admin')
    expect(within(rows[2]).getByText('You')).toBeInTheDocument()
    expect(within(rows[2]).getByRole('button', { name: 'Leave' })).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Members' })).toHaveAttribute('aria-current', 'page')
  })

  it('shows an error with Retry when the list can\'t load', async () => {
    logIn()
    api({ 'GET /api/members': () => jsonResponse({ message: 'Server Error' }, 500) })
    renderApp('/members')

    expect(await screen.findByRole('heading', { name: 'Could not load the members' }, { timeout: 3000 })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Retry' })).toBeInTheDocument()
  })

  it('hides what a member may not do', async () => {
    logIn({ ...ada, current_organization: { ...acme, role: memberRole, permissions: [] } })
    api({ 'GET /api/members': () => jsonResponse({ data: [ownerSeenByMember, { ...me, role: memberRole, assignable_roles: [], can: { update: false, delete: true } }] }) })
    renderApp('/members')

    const rows = await screen.findAllByRole('row')
    expect(within(rows[1]).queryByRole('combobox')).not.toBeInTheDocument()
    expect(within(rows[1]).getByText('Owner')).toBeInTheDocument()
    expect(within(rows[1]).queryByRole('button', { name: 'Remove' })).not.toBeInTheDocument()
    expect(screen.queryByRole('heading', { name: 'Invite someone' })).not.toBeInTheDocument()
    expect(screen.queryByRole('heading', { name: 'Pending invitations' })).not.toBeInTheDocument()
  })

  it('removes someone after confirming', async () => {
    logIn()
    let members = [ana, me]
    const fetchMock = api({
      'GET /api/members': () => jsonResponse({ data: members }),
      [`DELETE /api/members/${ana.id}`]: () => {
        members = [me]
        return jsonResponse(null, 204)
      },
    })
    const { user } = renderApp('/members')

    const rows = await screen.findAllByRole('row')
    await user.click(within(rows[1]).getByRole('button', { name: 'Remove' }))
    const dialog = await screen.findByRole('alertdialog', { name: 'Remove Ana Admin?' })
    await user.click(within(dialog).getByRole('button', { name: 'Remove' }))

    expect(await screen.findByText('Ana Admin was removed')).toBeInTheDocument()
    await waitFor(() => expect(screen.getAllByRole('row')).toHaveLength(2))
    expect(fetchMock.mock.calls.some(([url, init]) => String(url).endsWith(`/api/members/${ana.id}`) && init?.method === 'DELETE')).toBe(true)
  })

  it('cancels a removal without calling the API', async () => {
    logIn()
    const fetchMock = api()
    const { user } = renderApp('/members')

    const rows = await screen.findAllByRole('row')
    await user.click(within(rows[1]).getByRole('button', { name: 'Remove' }))
    await user.click(within(await screen.findByRole('alertdialog')).getByRole('button', { name: 'Cancel' }))

    expect(fetchMock.mock.calls.every(([, init]) => (init?.method ?? 'GET') === 'GET')).toBe(true)
  })

  it('changes a role with the keyboard', async () => {
    logIn()
    const fetchMock = api({ [`PATCH /api/members/${ana.id}`]: () => jsonResponse({ data: { ...ana, role: memberRole } }) })
    const { user } = renderApp('/members')

    const select = await screen.findByRole('combobox', { name: 'Role of Ana Admin' })
    select.focus()
    await user.keyboard('{Enter}')
    await user.click(await screen.findByRole('option', { name: 'Member' }))

    expect(await screen.findByText('Ana Admin now has the Member role')).toBeInTheDocument()
    const patch = fetchMock.mock.calls.find(([, init]) => init?.method === 'PATCH')
    expect(sentBody(patch?.[1] ?? {})).toEqual({ role_id: memberRole.id })
  })

  it('leaves: back home in the organization the server moved us to', async () => {
    logIn()
    queryClient.setQueryData(['org', acme.id, 'tasks'], ['Acme task'])
    api({
      [`DELETE /api/members/${me.id}`]: () => jsonResponse(null, 204),
      'GET /api/me': () => jsonResponse({ data: { ...ada, current_organization: beta } }),
    })
    const { router, user } = renderApp('/members')

    const rows = await screen.findAllByRole('row')
    await user.click(within(rows[2]).getByRole('button', { name: 'Leave' }))
    await user.click(within(await screen.findByRole('alertdialog', { name: 'Leave Acme?' })).getByRole('button', { name: 'Leave' }))

    expect(await screen.findByText('You left Acme')).toBeInTheDocument()
    await waitFor(() => expect(router.state.location.pathname).toBe('/'))
    expect(useAuth.getState().user?.current_organization).toEqual(beta)
    expect(queryClient.getQueryData(['org', acme.id, 'tasks'])).toBeUndefined()
  })

  it('catches up when another tab switched organization', async () => {
    logIn()
    // Like the real API: 409 while the header names Acme, the list once it names Beta.
    api({
      'GET /api/members': (init) =>
        (init.headers as Record<string, string>)['X-Organization-Id'] === String(acme.id)
          ? jsonResponse({ message: 'Your current organization changed.', code: 'organization_changed' }, 409)
          : jsonResponse({ data: [] }),
      'GET /api/me': () => jsonResponse({ data: { ...ada, current_organization: beta } }),
    })
    renderApp('/members')

    expect(await screen.findByText('Your organization changed')).toBeInTheDocument()
    await waitFor(() => expect(screen.getByRole('button', { name: 'Organization: Beta' })).toBeInTheDocument())
  })
})
