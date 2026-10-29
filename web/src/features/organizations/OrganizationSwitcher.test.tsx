import { screen, waitFor, within } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { queryClient } from '@/lib/queryClient'
import { useAuth } from '@/stores/auth'
import { acme, ada, beta, jsonResponse, logIn, mockApi, organizationList, renderApp } from '@/test/utils'

async function openSwitcher(user: ReturnType<typeof renderApp>['user']) {
  await user.click(await screen.findByRole('button', { name: 'Organization: Acme' }))
  return screen.findByRole('menu')
}

describe('OrganizationSwitcher', () => {
  it('lists your organizations with your role, the current one checked', async () => {
    logIn()
    mockApi({ 'GET /api/organizations': () => jsonResponse(organizationList([acme, beta])) })
    const { user } = renderApp('/')

    const menu = await openSwitcher(user)

    const acmeItem = await within(menu).findByRole('menuitemcheckbox', { name: /Acme/ })
    expect(acmeItem).toHaveAttribute('aria-checked', 'true')
    expect(acmeItem).toHaveTextContent('Owner')
    const betaItem = within(menu).getByRole('menuitemcheckbox', { name: /Beta/ })
    expect(betaItem).toHaveAttribute('aria-checked', 'false')
    expect(betaItem).toHaveTextContent('Member')
    expect(within(menu).getByRole('menuitem', { name: 'Create organization' })).toBeInTheDocument()
  })

  it('switches, and drops the previous organization\'s cached data', async () => {
    logIn()
    queryClient.setQueryData(['org', acme.id, 'tasks'], ['Acme task'])
    const fetchMock = mockApi({
      'GET /api/organizations': () => jsonResponse(organizationList([acme, beta])),
      [`POST /api/organizations/${beta.id}/switch`]: () => jsonResponse({ data: beta }),
    })
    const { user } = renderApp('/')

    const menu = await openSwitcher(user)
    await user.click(await within(menu).findByRole('menuitemcheckbox', { name: /Beta/ }))

    expect(await screen.findByRole('button', { name: 'Organization: Beta' })).toBeInTheDocument()
    expect(within(screen.getByRole('main')).getByText(/You're working in/)).toHaveTextContent('Beta as Member')
    expect(useAuth.getState().user?.current_organization).toEqual(beta)
    expect(queryClient.getQueryData(['org', acme.id, 'tasks'])).toBeUndefined()
    expect(fetchMock.mock.calls.some(([url, init]) => String(url).endsWith(`/switch`) && init?.method === 'POST')).toBe(true)
  })

  it('does nothing when you choose the current organization', async () => {
    logIn()
    const fetchMock = mockApi({ 'GET /api/organizations': () => jsonResponse(organizationList([acme, beta])) })
    const { user } = renderApp('/')

    const menu = await openSwitcher(user)
    await user.click(await within(menu).findByRole('menuitemcheckbox', { name: /Acme/ }))

    expect(fetchMock.mock.calls.every(([, init]) => (init?.method ?? 'GET') === 'GET')).toBe(true)
  })

  it('says so and reloads the list when the switch is refused', async () => {
    logIn()
    let listCalls = 0
    mockApi({
      'GET /api/organizations': () => {
        listCalls++
        return jsonResponse(organizationList([acme, beta]))
      },
      [`POST /api/organizations/${beta.id}/switch`]: () => jsonResponse({ message: 'No query results.' }, 404),
    })
    const { user } = renderApp('/')

    const menu = await openSwitcher(user)
    await user.click(await within(menu).findByRole('menuitemcheckbox', { name: /Beta/ }))

    expect(await screen.findByText('Could not switch organization')).toBeInTheDocument()
    expect(useAuth.getState().user?.current_organization).toEqual(ada.current_organization)
    // Once on mount and once when the menu opened, then again after the refusal.
    await waitFor(() => expect(listCalls).toBeGreaterThanOrEqual(3))
  })
})
