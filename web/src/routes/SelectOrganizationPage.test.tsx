import { screen, waitFor } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { chooseOrganizationTarget } from '@/features/organizations/afterLogin'
import { useAuth } from '@/stores/auth'
import { acme, ada, beta, jsonResponse, logIn, mockApi, organizationList, renderApp } from '@/test/utils'

async function logInThroughForm(user: ReturnType<typeof renderApp>['user']) {
  await user.type(screen.getByLabelText('Email'), 'ada@example.com')
  await user.type(screen.getByLabelText('Password'), 'secret-password')
  await user.click(screen.getByRole('button', { name: 'Log in' }))
}

describe('choosing an organization after login', () => {
  it('asks first when you are in more than one, then continues to ?redirect=', async () => {
    mockApi({
      'POST /api/login': () => jsonResponse({ user: ada, token: '1|new' }),
      'GET /api/organizations': () => jsonResponse(organizationList([acme, beta])),
      [`POST /api/organizations/${beta.id}/switch`]: () => jsonResponse({ data: beta }),
    })
    const { router, user } = renderApp('/login?redirect=%2F%3Ftab%3Dme')

    await logInThroughForm(user)

    expect(await screen.findByRole('heading', { name: 'Choose an organization' })).toBeInTheDocument()
    expect(router.state.location.pathname).toBe('/select-organization')
    expect(screen.getByRole('button', { name: /Acme.*Last used/ })).toBeInTheDocument()

    await user.click(screen.getByRole('button', { name: /Beta/ }))

    expect(await screen.findByRole('button', { name: 'Organization: Beta' })).toBeInTheDocument()
    expect(router.state.location.pathname + router.state.location.search).toBe('/?tab=me')
    expect(useAuth.getState().chooseOrganization).toBe(false)
  })

  it('makes no API call when you choose the one you were in', async () => {
    mockApi({
      'POST /api/login': () => jsonResponse({ user: ada, token: '1|new' }),
      'GET /api/organizations': () => jsonResponse(organizationList([acme, beta])),
    })
    const { router, user } = renderApp('/login')

    await logInThroughForm(user)
    await user.click(await screen.findByRole('button', { name: /Acme/ }))

    await waitFor(() => expect(router.state.location.pathname).toBe('/'))
    expect(useAuth.getState().user?.current_organization).toEqual(acme)
  })

  it('goes straight on with one organization', async () => {
    mockApi({
      'POST /api/login': () => jsonResponse({ user: ada, token: '1|new' }),
      'GET /api/organizations': () => jsonResponse(organizationList([acme])),
    })
    const { router, user } = renderApp('/login')

    await logInThroughForm(user)

    expect(await screen.findByRole('heading', { name: 'Welcome, Ada Lovelace' })).toBeInTheDocument()
    expect(router.state.location.pathname).toBe('/')
  })

  it('logs in anyway when the organizations cannot be loaded', async () => {
    mockApi({
      'POST /api/login': () => jsonResponse({ user: ada, token: '1|new' }),
      'GET /api/organizations': () => jsonResponse({ message: 'Server Error' }, 500),
    })
    const { router, user } = renderApp('/login')

    await logInThroughForm(user)

    await waitFor(() => expect(router.state.location.pathname).toBe('/'))
    expect(useAuth.getState().token).toBe('1|new')
  })

  it('skips the picker for an invitation link, and doesn\'t ask later either', async () => {
    mockApi({
      'POST /api/login': () => jsonResponse({ user: ada, token: '1|new' }),
      'GET /api/organizations': () => jsonResponse(organizationList([acme, beta])),
    })
    const { router, user } = renderApp('/login?redirect=%2Fjoin%2Fabc123')

    await logInThroughForm(user)

    await waitFor(() => expect(router.state.location.pathname).toBe('/join/abc123'))
    await waitFor(() => expect(useAuth.getState().chooseOrganization).toBe(false))
  })

  it('sends a one-organization user on when the picker is opened directly', async () => {
    logIn()
    mockApi({ 'GET /api/organizations': () => jsonResponse(organizationList([acme])) })
    const { router } = renderApp('/select-organization?redirect=%2F')

    await waitFor(() => expect(router.state.location.pathname).toBe('/'))
  })
})

describe('chooseOrganizationTarget', () => {
  it.each([
    ['/', '/select-organization?redirect=%2F'],
    ['/tasks?status=done', '/select-organization?redirect=%2Ftasks%3Fstatus%3Ddone'],
    ['/join/abc123', '/join/abc123'],
    ['/select-organization?redirect=%2F', '/select-organization?redirect=%2F'],
  ])('%s → %s', (redirect, target) => {
    expect(chooseOrganizationTarget(redirect)).toBe(target)
  })
})
