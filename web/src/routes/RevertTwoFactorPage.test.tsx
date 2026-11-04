import { screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { useAuth } from '@/stores/auth'
import { jsonResponse, logIn, mockApi, renderApp } from '@/test/utils'

const link = '/revert-two-factor?id=1&at=1700000000&expires=123&signature=sig'
const done =
  'Two-factor authentication is off again, and every device was signed out. Check your email for a link to set a new password.'

describe('RevertTwoFactorPage', () => {
  it('turns two-factor off while logged out, once', async () => {
    const fetchMock = mockApi({ 'POST /api/account/two-factor/revert/1/1700000000': () => jsonResponse({ message: done }) })
    renderApp(link)

    expect(await screen.findByText(done)).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Go to log in' })).toHaveAttribute('href', '/login')
    expect(fetchMock).toHaveBeenCalledTimes(1)
    expect(String(fetchMock.mock.calls[0][0])).toContain('expires=123&signature=sig')
  })

  it('logs this tab out too, since every device was signed out', async () => {
    logIn()
    mockApi({ 'POST /api/account/two-factor/revert/1/1700000000': () => jsonResponse({ message: done }) })
    renderApp(link)

    expect(await screen.findByText(done)).toBeInTheDocument()
    expect(useAuth.getState().token).toBeNull()
  })

  it('explains a used link, and an incomplete one', async () => {
    mockApi({
      'POST /api/account/two-factor/revert/1/1700000000': () =>
        jsonResponse({ message: 'This link was already used, or two-factor authentication was set up again since.' }, 403),
    })
    renderApp(link)

    expect(await screen.findByText("This link doesn't work")).toBeInTheDocument()
    expect(screen.getByText(/already used/)).toBeInTheDocument()
  })

  it('says to open the email again when the link is incomplete', () => {
    mockApi({})
    renderApp('/revert-two-factor?id=1')

    expect(screen.getByText('Open the link from the email again.')).toBeInTheDocument()
  })
})
