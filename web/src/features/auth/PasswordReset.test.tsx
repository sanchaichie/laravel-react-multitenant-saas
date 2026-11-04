import { screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { useAuth } from '@/stores/auth'
import { acme, ada, beta, jsonResponse, mockApi, organizationList, renderApp, sentBody } from '@/test/utils'

const sentMessage = "If an account uses that email, we've sent it a link to reset the password."

describe('Forgot password', () => {
  it('is linked from the login page and answers the same for any address', async () => {
    const fetchMock = mockApi({ 'POST /api/forgot-password': () => jsonResponse({ message: sentMessage }) })
    const { router, user } = renderApp('/login')

    await user.click(screen.getByRole('link', { name: 'Forgot password?' }))
    expect(router.state.location.pathname).toBe('/forgot-password')

    await user.type(await screen.findByLabelText('Email'), 'Ada@Example.com')
    await user.click(screen.getByRole('button', { name: 'Send reset link' }))

    expect(await screen.findByText('Check your email')).toBeInTheDocument()
    expect(screen.getByText(/we've sent it a link to reset the password/)).toBeInTheDocument()
    expect(sentBody(fetchMock.mock.calls[0][1] ?? {})).toEqual({ email: 'ada@example.com' })
  })

  it('says when there were too many tries', async () => {
    mockApi({ 'POST /api/forgot-password': () => jsonResponse({ message: 'Too Many Attempts.' }, 429) })
    const { user } = renderApp('/forgot-password')

    await user.type(screen.getByLabelText('Email'), 'ada@example.com')
    await user.click(screen.getByRole('button', { name: 'Send reset link' }))

    expect(await screen.findByText('Too many attempts. Wait a minute and try again.')).toBeInTheDocument()
  })
})

describe('Reset password', () => {
  it('sets the new password and logs in, then asks which organization when there are several', async () => {
    const fetchMock = mockApi({
      'POST /api/reset-password': () => jsonResponse({ user: ada, token: '1|reset' }),
      'GET /api/organizations': () => jsonResponse(organizationList([acme, beta])),
    })
    const { router, user } = renderApp('/reset-password?token=abc123&email=ada%40example.com')

    expect(screen.getByText('ada@example.com')).toBeInTheDocument()
    await user.type(screen.getByLabelText('New password'), 'new-secret-password')
    await user.type(screen.getByLabelText('Repeat the new password'), 'new-secret-password')
    await user.click(screen.getByRole('button', { name: 'Set new password' }))

    expect(await screen.findByRole('heading', { name: 'Choose an organization' })).toBeInTheDocument()
    expect(router.state.location.pathname).toBe('/select-organization')
    expect(useAuth.getState().token).toBe('1|reset')
    expect(sentBody(fetchMock.mock.calls[0][1] ?? {})).toEqual({
      token: 'abc123',
      email: 'ada@example.com',
      password: 'new-secret-password',
      password_confirmation: 'new-secret-password',
    })
  })

  it('offers a new link when this one is invalid or expired', async () => {
    mockApi({
      'POST /api/reset-password': () =>
        jsonResponse(
          {
            message: 'This password reset link is invalid or has expired.',
            errors: { email: ['This password reset link is invalid or has expired.'] },
          },
          422,
        ),
    })
    const { user } = renderApp('/reset-password?token=old&email=ada%40example.com')

    await user.type(screen.getByLabelText('New password'), 'new-secret-password')
    await user.type(screen.getByLabelText('Repeat the new password'), 'new-secret-password')
    await user.click(screen.getByRole('button', { name: 'Set new password' }))

    expect(await screen.findByText('This password reset link is invalid or has expired.')).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Ask for a new link' })).toHaveAttribute('href', '/forgot-password')
  })

  it('checks the new password before sending, and shows the API\'s rules on the field', async () => {
    const fetchMock = mockApi({
      'POST /api/reset-password': () =>
        jsonResponse({ message: 'Invalid', errors: { password: ['The password has appeared in a data leak.'] } }, 422),
    })
    const { user } = renderApp('/reset-password?token=abc&email=ada%40example.com')

    await user.type(screen.getByLabelText('New password'), 'short')
    await user.click(screen.getByRole('button', { name: 'Set new password' }))
    expect(await screen.findByText('At least 8 characters.')).toBeInTheDocument()
    expect(fetchMock).not.toHaveBeenCalled()

    await user.clear(screen.getByLabelText('New password'))
    await user.type(screen.getByLabelText('New password'), 'password123')
    await user.type(screen.getByLabelText('Repeat the new password'), 'password123')
    await user.click(screen.getByRole('button', { name: 'Set new password' }))
    expect(await screen.findByText('The password has appeared in a data leak.')).toBeInTheDocument()
  })

  it('says so when the link is incomplete', () => {
    mockApi({})
    renderApp('/reset-password?token=abc')

    expect(screen.getByText('This link is incomplete')).toBeInTheDocument()
  })
})
