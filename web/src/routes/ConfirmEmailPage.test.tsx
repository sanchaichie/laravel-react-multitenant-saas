import { screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { useAuth } from '@/stores/auth'
import { ada, jsonResponse, logIn, mockApi, renderApp } from '@/test/utils'

const link = '/confirm-email?id=1&hash=abc&expires=123&signature=sig'

describe('ConfirmEmailPage', () => {
  it('posts the link once and shows the new email', async () => {
    logIn({ ...ada, pending_email: 'ada@new.example' })
    const fetchMock = mockApi({
      'POST /api/account/email/confirm/1/abc': () =>
        jsonResponse({ data: { ...ada, email: 'ada@new.example', pending_email: null } }),
    })
    renderApp(link)

    expect(await screen.findByText('ada@new.example')).toBeInTheDocument()
    expect(screen.getByText(/Your other devices were signed out/)).toBeInTheDocument()
    expect(useAuth.getState().user).toMatchObject({ email: 'ada@new.example', pending_email: null })
    const [url] = fetchMock.mock.calls[0]
    expect(new URL(String(url)).search).toBe('?expires=123&signature=sig')
    expect(fetchMock).toHaveBeenCalledTimes(1)
  })

  it('explains a link that doesn\'t work', async () => {
    logIn()
    mockApi({
      'POST /api/account/email/confirm/1/abc': () =>
        jsonResponse({ message: 'This link is for a different account, or for a change that was replaced or cancelled.' }, 403),
    })
    renderApp(link)

    expect(await screen.findByText("This link doesn't work")).toBeInTheDocument()
    expect(screen.getByText(/for a change that was replaced or cancelled/)).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Go to Account settings' })).toHaveAttribute('href', '/account')
  })

  it('sends you to log in first when logged out, and back here after', async () => {
    mockApi({})
    const { router } = renderApp(link)

    expect(await screen.findByRole('heading', { name: 'Log in' })).toBeInTheDocument()
    expect(router.state.location.search).toContain('redirect=%2Fconfirm-email')
  })
})
