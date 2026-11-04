import { screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { useAuth } from '@/stores/auth'
import { jsonResponse, logIn, mockApi, renderApp } from '@/test/utils'

const link = '/revert-email?id=1&old=aaa&new=bbb&expires=123&signature=sig'
const done = 'Your old email address is back, and every device was signed out. Check it for a link to set a new password.'

describe('RevertEmailPage', () => {
  it('undoes the change while logged out', async () => {
    const fetchMock = mockApi({ 'POST /api/account/email/revert/1/aaa/bbb': () => jsonResponse({ message: done }) })
    renderApp(link)

    expect(await screen.findByText(done)).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Go to log in' })).toHaveAttribute('href', '/login')
    expect(fetchMock).toHaveBeenCalledTimes(1)
  })

  it('logs this tab out too, since every device was signed out', async () => {
    logIn()
    mockApi({ 'POST /api/account/email/revert/1/aaa/bbb': () => jsonResponse({ message: done }) })
    renderApp(link)

    expect(await screen.findByText(done)).toBeInTheDocument()
    expect(useAuth.getState().token).toBeNull()
  })

  it('explains a used or invalid link', async () => {
    mockApi({
      'POST /api/account/email/revert/1/aaa/bbb': () =>
        jsonResponse({ message: 'This link was already used, or the email has changed again since.' }, 403),
    })
    renderApp(link)

    expect(await screen.findByText("This link doesn't work")).toBeInTheDocument()
    expect(screen.getByText(/already used/)).toBeInTheDocument()
  })
})
