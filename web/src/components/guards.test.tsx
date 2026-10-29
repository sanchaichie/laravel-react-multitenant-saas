import { screen, waitFor, within } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { bootstrapSession } from '@/features/auth/session'
import { useAuth } from '@/stores/auth'
import { ada, jsonResponse, logIn, logOutFromMenu, mockApi, renderApp } from '@/test/utils'

describe('RequireAuth', () => {
  it('sends a logged-out visitor to log in, remembering where they were', async () => {
    const { router } = renderApp('/')

    expect(await screen.findByRole('heading', { name: 'Log in' })).toBeInTheDocument()
    expect(router.state.location.pathname + router.state.location.search).toBe('/login?redirect=%2F')
  })

  it('shows home with the user once logged in', async () => {
    logIn()
    renderApp('/')

    expect(await screen.findByRole('heading', { name: 'Welcome, Ada Lovelace' })).toBeInTheDocument()
    const page = within(screen.getByRole('main'))
    expect(page.getByText('ada@example.com')).toBeInTheDocument()
    expect(page.getByText('Oct 1, 2026')).toBeInTheDocument()
    expect(page.getByText('Owner')).toBeInTheDocument()
  })

  it('offers a retry when there is a token but the API was down at startup', async () => {
    useAuth.setState({ token: '1|saved', user: null })
    mockApi({ 'GET /api/me': () => jsonResponse({ data: ada }) })
    const { user } = renderApp('/')

    expect(await screen.findByRole('heading', { name: 'Couldn\'t load your account' })).toBeInTheDocument()
    await user.click(screen.getByRole('button', { name: 'Retry' }))

    expect(await screen.findByRole('heading', { name: 'Welcome, Ada Lovelace' })).toBeInTheDocument()
  })

  it('says why a retry failed', async () => {
    useAuth.setState({ token: '1|saved', user: null })
    mockApi({
      'GET /api/me': () => {
        throw new TypeError('Failed to fetch')
      },
    })
    const { user } = renderApp('/')

    await user.click(await screen.findByRole('button', { name: 'Retry' }))

    expect(await screen.findByText('Cannot reach the server. Is the API running?')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Retry' })).toBeEnabled()
  })

  it('keeps a newer session that started while /api/me was loading', async () => {
    const grace = { ...ada, id: 2, name: 'Grace Hopper' }
    useAuth.setState({ token: '1|saved', user: null })
    mockApi({
      'GET /api/me': () => {
        logIn(grace, '2|new')
        return jsonResponse({ data: ada })
      },
    })

    await bootstrapSession()

    expect(useAuth.getState()).toMatchObject({ token: '2|new', user: grace })
  })

  it('goes to log in when a request finds the token revoked', async () => {
    logIn()
    mockApi({ 'GET /api/me': () => jsonResponse({ message: 'Unauthenticated.' }, 401) })
    const { router } = renderApp('/')
    await screen.findByRole('heading', { name: 'Welcome, Ada Lovelace' })

    useAuth.setState({ user: null })
    await bootstrapSession().catch(() => {})

    await waitFor(() => expect(router.state.location.pathname).toBe('/login'))
  })
})

describe('RequireGuest', () => {
  it('sends a logged-in user away from the login page', async () => {
    logIn()
    const { router } = renderApp('/login')

    await waitFor(() => expect(router.state.location.pathname).toBe('/'))
  })
})

describe('log out', () => {
  it('revokes the token and goes to log in without a redirect back', async () => {
    logIn()
    const fetchMock = mockApi({ 'POST /api/logout': () => jsonResponse(null, 204) })
    const { router, user } = renderApp('/')

    await logOutFromMenu(user)

    await waitFor(() => expect(router.state.location.pathname).toBe('/login'))
    expect(router.state.location.search).toBe('')
    expect(useAuth.getState()).toMatchObject({ token: null, user: null })
    expect(fetchMock.mock.calls.filter(([url]) => String(url).endsWith('/api/logout'))).toHaveLength(1)
  })

  it('logs out locally even if the token was already revoked', async () => {
    logIn()
    mockApi({ 'POST /api/logout': () => jsonResponse({ message: 'Unauthenticated.' }, 401) })
    const { router, user } = renderApp('/')

    await logOutFromMenu(user)

    await waitFor(() => expect(router.state.location.pathname).toBe('/login'))
    expect(router.state.location.search).toBe('')
    expect(useAuth.getState().token).toBeNull()
    expect(screen.queryByText('Logged out on this device')).not.toBeInTheDocument()
  })

  it('warns when the server could not confirm it', async () => {
    logIn()
    mockApi({
      'POST /api/logout': () => {
        throw new TypeError('Failed to fetch')
      },
    })
    const { router, user } = renderApp('/')

    await logOutFromMenu(user)

    expect(await screen.findByText('Logged out on this device')).toBeInTheDocument()
    expect(router.state.location.pathname + router.state.location.search).toBe('/login')
    expect(useAuth.getState().token).toBeNull()
  })
})

describe('not found', () => {
  it('shows a page with a way home', async () => {
    renderApp('/nope')

    expect(await screen.findByRole('heading', { name: 'Page not found' })).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Go home' })).toHaveAttribute('href', '/')
  })
})
