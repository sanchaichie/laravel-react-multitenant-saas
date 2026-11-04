import { screen, waitFor, within } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { useAuth } from '@/stores/auth'
import { acme, ada, beta, jsonResponse, logIn, mockApi, organizationList, renderApp, sentBody } from '@/test/utils'
import type { Device } from '@/types/api'

type Handler = (init: RequestInit, url: URL) => Response

const thisDevice: Device = {
  id: 1,
  name: 'Chrome on macOS',
  ip_address: '127.0.0.1',
  last_used_at: '2026-11-04T09:00:00.000000Z',
  created_at: '2026-11-01T09:00:00.000000Z',
  is_current: true,
}
const phone: Device = { ...thisDevice, id: 2, name: 'Safari on iOS', ip_address: '10.0.0.7', is_current: false }

function api(overrides: Record<string, Handler> = {}) {
  return mockApi({
    'GET /api/organizations': () => jsonResponse(organizationList([acme, beta])),
    'GET /api/account/devices': () => jsonResponse({ data: [thisDevice, phone] }),
    ...overrides,
  })
}

const card = (name: string) => screen.findByRole('heading', { name }).then((heading) => heading.closest('[data-slot="card"]') as HTMLElement)

describe('AccountPage', () => {
  it('is in the user menu', async () => {
    logIn()
    api({ 'GET /api/me': () => jsonResponse({ data: ada }) })
    const { router, user } = renderApp('/')

    await user.click(await screen.findByRole('button', { name: `Account: ${ada.name}` }))
    await user.click(await screen.findByRole('menuitem', { name: 'Account settings' }))

    expect(router.state.location.pathname).toBe('/account')
    expect(await screen.findByRole('heading', { name: 'Account settings' })).toBeInTheDocument()
  })

  it('saves the name and shows it in the menu', async () => {
    logIn()
    const fetchMock = api({ 'PATCH /api/account': (init) => jsonResponse({ data: { ...ada, ...(sentBody(init) as object) } }) })
    const { user } = renderApp('/account')

    const profile = await card('Profile')
    const name = within(profile).getByLabelText('Name')
    expect(within(profile).getByRole('button', { name: 'Save name' })).toBeDisabled()
    await user.clear(name)
    await user.type(name, 'Ada King')
    await user.click(within(profile).getByRole('button', { name: 'Save name' }))

    await waitFor(() => expect(useAuth.getState().user?.name).toBe('Ada King'))
    expect(await screen.findByText('Name saved')).toBeInTheDocument()
    expect(sentBody(fetchMock.mock.calls.find(([, init]) => init?.method === 'PATCH')?.[1] ?? {})).toEqual({ name: 'Ada King' })
  })

  it('asks to change the email with the password, shows it as pending, and cancels it', async () => {
    logIn()
    let wrong = true
    api({
      'PUT /api/account/email': (init) =>
        wrong
          ? jsonResponse({ message: 'Invalid', errors: { current_password: ['The password is incorrect.'] } }, 422)
          : jsonResponse({ data: { ...ada, pending_email: (sentBody(init) as { email: string }).email } }),
      'DELETE /api/account/email/pending': () => jsonResponse({ data: { ...ada, pending_email: null } }),
    })
    const { user } = renderApp('/account')

    const emailCard = await card('Email')
    await user.type(within(emailCard).getByLabelText('New email'), 'Ada@New.example')
    await user.type(within(emailCard).getByLabelText('Current password'), 'nope')
    await user.click(within(emailCard).getByRole('button', { name: 'Send confirmation link' }))
    expect(await within(emailCard).findByText('The password is incorrect.')).toBeInTheDocument()

    wrong = false
    await user.click(within(emailCard).getByRole('button', { name: 'Send confirmation link' }))

    expect(await within(emailCard).findByText('Waiting for confirmation')).toBeInTheDocument()
    expect(within(emailCard).getByText('ada@new.example')).toBeInTheDocument()
    expect(useAuth.getState().user).toMatchObject({ email: ada.email, pending_email: 'ada@new.example' })

    await user.click(within(emailCard).getByRole('button', { name: 'Cancel change' }))
    await waitFor(() => expect(within(emailCard).queryByText('Waiting for confirmation')).not.toBeInTheDocument())
    expect(useAuth.getState().user?.pending_email).toBeNull()
  })


  it('changes the password', async () => {
    logIn()
    const fetchMock = api({ 'PUT /api/account/password': () => new Response(null, { status: 204 }) })
    const { user } = renderApp('/account')

    const passwordCard = await card('Password')
    await user.type(within(passwordCard).getByLabelText('Current password'), 'secret-password')
    await user.type(within(passwordCard).getByLabelText('New password'), 'even-better-password')
    await user.type(within(passwordCard).getByLabelText('Repeat the new password'), 'even-better-password')
    await user.click(within(passwordCard).getByRole('button', { name: 'Change password' }))

    expect(await screen.findByText('Password changed')).toBeInTheDocument()
    expect(within(passwordCard).getByLabelText('New password')).toHaveValue('')
    expect(sentBody(fetchMock.mock.calls.find(([, init]) => init?.method === 'PUT')?.[1] ?? {})).toEqual({
      current_password: 'secret-password',
      password: 'even-better-password',
      password_confirmation: 'even-better-password',
    })
  })

  it('lists devices and signs out another one', async () => {
    logIn()
    api({ 'DELETE /api/account/devices/2': () => new Response(null, { status: 204 }) })
    const { user } = renderApp('/account')

    const table = await screen.findByRole('table', { name: 'Signed-in devices' })
    expect(within(table).getByText('This device')).toBeInTheDocument()
    await user.click(within(table).getByRole('button', { name: 'Sign out Safari on iOS' }))
    await user.click(within(await screen.findByRole('alertdialog')).getByRole('button', { name: 'Sign out' }))

    await waitFor(() => expect(within(table).queryByText('Safari on iOS')).not.toBeInTheDocument())
    expect(useAuth.getState().token).not.toBeNull()
  })

  it('logs out when signing out this device', async () => {
    logIn()
    api({ 'DELETE /api/account/devices/1': () => new Response(null, { status: 204 }) })
    const { router, user } = renderApp('/account')

    const table = await screen.findByRole('table', { name: 'Signed-in devices' })
    await user.click(within(table).getByRole('button', { name: 'Sign out Chrome on macOS' }))
    await user.click(within(await screen.findByRole('alertdialog')).getByRole('button', { name: 'Sign out' }))

    await waitFor(() => expect(router.state.location.pathname).toBe('/login'))
    expect(useAuth.getState().token).toBeNull()
  })

  it('shows what blocks deleting the account, then deletes it and logs out', async () => {
    logIn()
    let blocked = true
    api({
      'DELETE /api/account': () =>
        blocked
          ? jsonResponse(
              {
                message: 'Blocked',
                errors: { account: ["You're the last owner of Acme, which has other members. Make one of them Owner first."] },
              },
              422,
            )
          : new Response(null, { status: 204 }),
    })
    const { router, user } = renderApp('/account')

    await user.click(await screen.findByRole('button', { name: 'Delete account…' }))
    const dialog = await screen.findByRole('dialog', { name: 'Delete your account?' })
    await user.type(within(dialog).getByLabelText('Password'), 'secret-password')
    await user.click(within(dialog).getByRole('button', { name: 'Delete my account' }))

    expect(await within(dialog).findByText(/You're the last owner of Acme/)).toBeInTheDocument()
    expect(within(dialog).getByText(/then change roles on its Members page/)).toBeInTheDocument()

    blocked = false
    await user.click(within(dialog).getByRole('button', { name: 'Delete my account' }))

    await waitFor(() => expect(router.state.location.pathname).toBe('/login'))
    expect(useAuth.getState().token).toBeNull()
    expect(await screen.findByText('Your account was deleted')).toBeInTheDocument()
  })

  it('shows an error with Retry when the devices can\'t load', async () => {
    logIn()
    let fail = true
    api({ 'GET /api/account/devices': () => (fail ? jsonResponse({ message: 'Server error' }, 500) : jsonResponse({ data: [thisDevice] })) })
    const { user } = renderApp('/account')

    expect(await screen.findByText(/Could not load your devices/, {}, { timeout: 3000 })).toBeInTheDocument()
    fail = false
    await user.click(screen.getByRole('button', { name: 'Retry' }))
    expect(await screen.findByText('This device')).toBeInTheDocument()
  })
})
