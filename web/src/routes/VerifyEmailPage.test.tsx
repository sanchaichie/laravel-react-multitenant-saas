import { screen, waitFor, within } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { useAuth } from '@/stores/auth'
import { acme, ada, jsonResponse, logIn, mockApi, organizationList, renderApp } from '@/test/utils'

const link = `/verify-email?id=${ada.id}&hash=${'b'.repeat(40)}&expires=1900000000&signature=${'c'.repeat(64)}`
const verifyPath = `POST /api/email/verify/${ada.id}/${'b'.repeat(40)}`

describe('VerifyEmailPage', () => {
  it('posts the link once and shows success', async () => {
    logIn({ ...ada, email_verified: false })
    const fetchMock = mockApi({ [verifyPath]: () => jsonResponse({ data: { ...ada, email_verified: true } }) })
    renderApp(link)

    expect(await screen.findByRole('heading', { name: 'Email verified' })).toBeInTheDocument()
    expect(useAuth.getState().user?.email_verified).toBe(true)
    // Once, even though StrictMode-style double effects would try twice.
    const posts = fetchMock.mock.calls.filter(([url]) => String(url).includes('/api/email/verify/'))
    expect(posts).toHaveLength(1)
    expect(String(posts[0][0])).toContain(`?expires=1900000000&signature=${'c'.repeat(64)}`)
  })

  it('offers a new link when this one doesn\'t work', async () => {
    logIn({ ...ada, email_verified: false })
    mockApi({
      [verifyPath]: () => jsonResponse({ message: 'Invalid signature.' }, 403),
      'POST /api/email/verification-notification': () =>
        jsonResponse({ message: 'We sent a new verification link to your email.', email_verified: false }, 202),
    })
    const { user } = renderApp(link)

    expect(await screen.findByRole('heading', { name: "This link doesn't work" })).toBeInTheDocument()
    await user.click(screen.getByRole('button', { name: 'Send a new link' }))

    expect(await screen.findByText('We sent a new verification link to your email.')).toBeInTheDocument()
  })

  it('says so when the link is incomplete', async () => {
    logIn({ ...ada, email_verified: false })
    mockApi({})
    renderApp('/verify-email?id=1')

    expect(await screen.findByRole('heading', { name: 'This link is incomplete' })).toBeInTheDocument()
  })
})

describe('VerifyEmailBanner', () => {
  it('shows until verified, with Resend', async () => {
    logIn({ ...ada, email_verified: false })
    mockApi({
      'GET /api/organizations': () => jsonResponse(organizationList([acme])),
      'POST /api/email/verification-notification': () =>
        jsonResponse({ message: 'We sent a new verification link to your email.', email_verified: false }, 202),
    })
    const { user } = renderApp('/')

    const banner = (await screen.findByText('Verify your email')).closest('[data-slot="alert"]') as HTMLElement
    expect(banner).toHaveTextContent('ada@example.com')
    await user.click(within(banner).getByRole('button', { name: 'Resend' }))

    expect(await screen.findByText('We sent a new verification link to your email.')).toBeInTheDocument()
  })

  it('goes away when Resend finds the email already verified (another tab)', async () => {
    logIn({ ...ada, email_verified: false })
    mockApi({
      'GET /api/organizations': () => jsonResponse(organizationList([acme])),
      'POST /api/email/verification-notification': () =>
        jsonResponse({ message: 'Your email is already verified.', email_verified: true }),
      'GET /api/me': () => jsonResponse({ data: { ...ada, email_verified: true } }),
    })
    const { user } = renderApp('/')

    await user.click(await screen.findByRole('button', { name: 'Resend' }))

    await waitFor(() => expect(screen.queryByText('Verify your email')).not.toBeInTheDocument())
    expect(useAuth.getState().user?.email_verified).toBe(true)
  })

  it('isn\'t shown once verified', async () => {
    logIn()
    mockApi({ 'GET /api/organizations': () => jsonResponse(organizationList([acme])) })
    renderApp('/')

    await screen.findByRole('heading', { name: 'Welcome, Ada Lovelace' })
    expect(screen.queryByText('Verify your email')).not.toBeInTheDocument()
  })
})
