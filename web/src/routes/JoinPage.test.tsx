import { screen, waitFor } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { useAuth } from '@/stores/auth'
import { acme, ada, adminRole, beta, jsonResponse, logIn, mockApi, organizationList, renderApp } from '@/test/utils'
import type { InvitationPreview } from '@/types/api'

const token = 'a'.repeat(48)
const preview: InvitationPreview = {
  organization: { name: 'Beta' },
  invited_by: 'Maya',
  role: adminRole,
  email: 'ada@example.com',
  expires_at: '2026-11-05T12:00:00.000000Z',
  already_member: false,
}

describe('JoinPage', () => {
  it('shows the invitation and joins: the new organization becomes current', async () => {
    logIn()
    mockApi({
      [`GET /api/join/${token}`]: () => jsonResponse({ data: preview }),
      [`POST /api/join/${token}`]: () => jsonResponse({ data: { ...beta, role: adminRole } }),
      'GET /api/me': () => jsonResponse({ data: { ...ada, current_organization: { ...beta, role: adminRole } } }),
      'GET /api/organizations': () => jsonResponse(organizationList([acme, beta], beta.id)),
    })
    const { router, user } = renderApp(`/join/${token}`)

    expect(await screen.findByRole('heading', { name: 'Join Beta' })).toBeInTheDocument()
    expect(screen.getByText('Maya invited you to join with the Admin role.')).toBeInTheDocument()

    await user.click(screen.getByRole('button', { name: 'Accept invitation' }))

    expect(await screen.findByText('You joined Beta')).toBeInTheDocument()
    await waitFor(() => expect(router.state.location.pathname).toBe('/'))
    expect(useAuth.getState().user?.current_organization?.id).toBe(beta.id)
  })

  it('says when the invitation is for another account, and offers to switch', async () => {
    logIn()
    mockApi({
      [`GET /api/join/${token}`]: () => jsonResponse({ message: 'This invitation was sent to a different email address.' }, 403),
      'POST /api/logout': () => jsonResponse(null, 204),
    })
    const { router, user } = renderApp(`/join/${token}`)

    expect(await screen.findByRole('heading', { name: 'This invitation is for another email address' })).toBeInTheDocument()
    await user.click(screen.getByRole('button', { name: 'Log out and switch account' }))

    await waitFor(() => expect(router.state.location.pathname).toBe('/login'))
    expect(router.state.location.search).toBe(`?redirect=${encodeURIComponent(`/join/${token}`)}`)
  })

  it('says when the link no longer works', async () => {
    logIn()
    mockApi({ [`GET /api/join/${token}`]: () => jsonResponse({ message: 'This invitation is no longer valid.' }, 404) })
    renderApp(`/join/${token}`)

    expect(await screen.findByRole('heading', { name: 'This invitation is no longer valid' })).toBeInTheDocument()
  })

  it('sends a logged-out visitor through login and back', async () => {
    mockApi({
      'POST /api/login': () => jsonResponse({ user: ada, token: '1|new' }),
      'GET /api/organizations': () => jsonResponse(organizationList([acme, beta])),
      [`GET /api/join/${token}`]: () => jsonResponse({ data: preview }),
    })
    const { router, user } = renderApp(`/join/${token}`)

    expect(await screen.findByRole('heading', { name: 'Log in' })).toBeInTheDocument()
    // The register link keeps the way back.
    expect(screen.getByRole('link', { name: 'Create one' })).toHaveAttribute('href', `/register?redirect=%2Fjoin%2F${token}`)

    await user.type(screen.getByLabelText('Email'), 'ada@example.com')
    await user.type(screen.getByLabelText('Password'), 'secret-password')
    await user.click(screen.getByRole('button', { name: 'Log in' }))

    // Two organizations, but an invitation link skips the picker.
    expect(await screen.findByRole('heading', { name: 'Join Beta' })).toBeInTheDocument()
    expect(router.state.location.pathname).toBe(`/join/${token}`)
  })
})
