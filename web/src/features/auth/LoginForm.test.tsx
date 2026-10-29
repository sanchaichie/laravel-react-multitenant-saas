import { screen, waitFor } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { useAuth } from '@/stores/auth'
import { ada, jsonResponse, mockApi, renderApp, sentBody } from '@/test/utils'

describe('LoginForm', () => {
  it('checks the fields before sending anything', async () => {
    const fetchMock = mockApi({})
    const { user } = renderApp('/login')

    await user.click(screen.getByRole('button', { name: 'Log in' }))

    expect(await screen.findByText('Enter your email.')).toBeInTheDocument()
    expect(screen.getByText('Enter your password.')).toBeInTheDocument()
    expect(screen.getByLabelText('Email')).toHaveFocus()
    expect(fetchMock).not.toHaveBeenCalled()
  })

  it('shows the API\'s error under the email field', async () => {
    mockApi({
      'POST /api/login': () =>
        jsonResponse({ message: 'These credentials do not match our records.', errors: { email: ['These credentials do not match our records.'] } }, 422),
    })
    const { user } = renderApp('/login')

    await user.type(screen.getByLabelText('Email'), 'ada@example.com')
    await user.type(screen.getByLabelText('Password'), 'wrong-password')
    await user.click(screen.getByRole('button', { name: 'Log in' }))

    expect(await screen.findByText('These credentials do not match our records.')).toBeInTheDocument()
    expect(screen.getByLabelText('Email')).toHaveAttribute('aria-invalid', 'true')
    expect(screen.getByLabelText('Password')).toHaveValue('wrong-password')
    expect(useAuth.getState().token).toBeNull()
  })

  it('stores the session and goes to ?redirect=', async () => {
    const fetchMock = mockApi({ 'POST /api/login': () => jsonResponse({ user: ada, token: '1|new' }) })
    const { router, user } = renderApp('/login?redirect=%2F%3Ftab%3Dme')

    await user.type(screen.getByLabelText('Email'), 'ada@example.com')
    await user.type(screen.getByLabelText('Password'), 'secret-password')
    await user.click(screen.getByRole('button', { name: 'Log in' }))

    expect(await screen.findByRole('heading', { name: 'Welcome, Ada Lovelace' })).toBeInTheDocument()
    expect(router.state.location.pathname + router.state.location.search).toBe('/?tab=me')
    expect(useAuth.getState()).toMatchObject({ token: '1|new', user: ada })
    expect(sentBody(fetchMock.mock.calls[0][1] ?? {})).toEqual({ email: 'ada@example.com', password: 'secret-password' })
  })

  it('never redirects off the site', async () => {
    mockApi({ 'POST /api/login': () => jsonResponse({ user: ada, token: '1|new' }) })
    const { router, user } = renderApp('/login?redirect=%2F%2Fevil.example')

    await user.type(screen.getByLabelText('Email'), 'ada@example.com')
    await user.type(screen.getByLabelText('Password'), 'secret-password')
    await user.click(screen.getByRole('button', { name: 'Log in' }))

    await waitFor(() => expect(router.state.location.pathname).toBe('/'))
    expect(router.state.location.search).toBe('')
  })

  it('shows a toast when the API cannot be reached', async () => {
    mockApi({
      'POST /api/login': () => {
        throw new TypeError('Failed to fetch')
      },
    })
    const { user } = renderApp('/login')

    await user.type(screen.getByLabelText('Email'), 'ada@example.com')
    await user.type(screen.getByLabelText('Password'), 'secret-password')
    await user.click(screen.getByRole('button', { name: 'Log in' }))

    expect(await screen.findByText('Cannot reach the server. Is the API running?')).toBeInTheDocument()
  })
})
