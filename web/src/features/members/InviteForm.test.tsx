import { screen, within } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { acme, ada, adminRole, jsonResponse, logIn, memberRole, ownerRole, mockApi, organizationList, renderApp, sentBody } from '@/test/utils'
import type { Invitation, Role } from '@/types/api'

const invitation: Invitation = {
  id: 7,
  email: 'ben@example.com',
  role: adminRole,
  invited_by: 'Ada Lovelace',
  expires_at: '2026-11-05T12:00:00.000000Z',
  expired: false,
  created_at: '2026-10-29T12:00:00.000000Z',
  can: { delete: true },
}

function invitationList(data: Invitation[], invitable: Role[] = [ownerRole, adminRole, memberRole]) {
  return { data, meta: { invitable_roles: invitable, default_role_id: invitable.at(-1)?.id ?? null } }
}

function api(overrides: Record<string, (init: RequestInit) => Response> = {}) {
  return mockApi({
    'GET /api/organizations': () => jsonResponse(organizationList([acme])),
    'GET /api/members': () => jsonResponse({ data: [] }),
    'GET /api/invitations': () => jsonResponse(invitationList([])),
    ...overrides,
  })
}

async function inviteForm() {
  const heading = await screen.findByRole('heading', { name: 'Invite someone' })
  return heading.closest('[data-slot="card"]') as HTMLElement
}

describe('InviteForm', () => {
  it('checks the email before sending anything', async () => {
    logIn()
    const fetchMock = api()
    const { user } = renderApp('/members')

    const form = await inviteForm()
    await user.click(within(form).getByRole('button', { name: 'Invite' }))

    expect(await within(form).findByText('Enter an email address.')).toBeInTheDocument()
    expect(within(form).getByLabelText('Email')).toHaveAccessibleDescription('Enter an email address.')
    expect(fetchMock.mock.calls.every(([, init]) => (init?.method ?? 'GET') === 'GET')).toBe(true)
  })

  it('invites with the chosen role and lists the invitation', async () => {
    logIn()
    let pending: Invitation[] = []
    const fetchMock = api({
      'GET /api/invitations': () => jsonResponse(invitationList(pending)),
      'POST /api/invitations': () => {
        pending = [invitation]
        return jsonResponse({ data: invitation }, 201)
      },
    })
    const { user } = renderApp('/members')

    const form = await inviteForm()
    await user.type(within(form).getByLabelText('Email'), 'Ben@Example.com')
    within(form).getByRole('combobox', { name: 'Role' }).focus()
    await user.keyboard('{Enter}')
    await user.click(await screen.findByRole('option', { name: 'Admin' }))
    await user.click(within(form).getByRole('button', { name: 'Invite' }))

    expect(await screen.findByText('Invitation sent')).toBeInTheDocument()
    expect(await screen.findByText('ben@example.com')).toBeInTheDocument()
    expect(within(form).getByLabelText('Email')).toHaveValue('')
    const post = fetchMock.mock.calls.find(([, init]) => init?.method === 'POST')
    expect(sentBody(post?.[1] ?? {})).toEqual({ email: 'ben@example.com', role_id: adminRole.id })
  })

  it('offers only the roles the API says you may invite with', async () => {
    logIn({ ...ada, current_organization: { ...acme, role: adminRole, permissions: ['members.invite', 'members.manage'] } })
    api({ 'GET /api/invitations': () => jsonResponse(invitationList([], [memberRole, adminRole])) })
    const { user } = renderApp('/members')

    const form = await inviteForm()
    within(form).getByRole('combobox', { name: 'Role' }).focus()
    await user.keyboard('{Enter}')

    const options = await screen.findAllByRole('option')
    expect(options.map((option) => option.textContent)).toEqual(['Admin', 'Member'])
  })

  it('shows the API\'s error on the email field', async () => {
    logIn()
    api({
      'POST /api/invitations': () =>
        jsonResponse({ message: 'Invalid.', errors: { email: ['This person is already a member of the organization.'] } }, 422),
    })
    const { user } = renderApp('/members')

    const form = await inviteForm()
    await user.type(within(form).getByLabelText('Email'), 'ana@example.com')
    await user.click(within(form).getByRole('button', { name: 'Invite' }))

    expect(await within(form).findByText('This person is already a member of the organization.')).toBeInTheDocument()
  })

  it('explains that an unverified email can\'t invite', async () => {
    logIn({ ...ada, email_verified: false })
    api({
      'POST /api/invitations': () =>
        jsonResponse({ message: 'Verify your email address to use this feature.', code: 'email_unverified' }, 403),
    })
    const { user } = renderApp('/members')

    expect(await screen.findByText('Verify your email')).toBeInTheDocument() // the banner
    const form = await inviteForm()
    await user.type(within(form).getByLabelText('Email'), 'ben@example.com')
    await user.click(within(form).getByRole('button', { name: 'Invite' }))

    expect(await screen.findByText(/Verify your email address first/)).toBeInTheDocument()
  })

  it('revokes a pending invitation after confirming', async () => {
    logIn()
    let pending = [invitation]
    api({
      'GET /api/invitations': () => jsonResponse(invitationList(pending)),
      [`DELETE /api/invitations/${invitation.id}`]: () => {
        pending = []
        return jsonResponse(null, 204)
      },
    })
    const { user } = renderApp('/members')

    await user.click(await screen.findByRole('button', { name: 'Revoke' }))
    await user.click(within(await screen.findByRole('alertdialog')).getByRole('button', { name: 'Revoke' }))

    expect(await screen.findByText('Invitation revoked')).toBeInTheDocument()
    expect(await screen.findByText('No pending invitations.')).toBeInTheDocument()
  })

  it('marks an invitation whose role was deleted, and starts the form on the API\'s default role', async () => {
    logIn()
    api({ 'GET /api/invitations': () => jsonResponse(invitationList([{ ...invitation, role: null }])) })
    renderApp('/members')

    expect(await screen.findByText('Role deleted')).toBeInTheDocument()
    expect(within(await inviteForm()).getByRole('combobox', { name: 'Role' })).toHaveTextContent('Member')
  })
})
