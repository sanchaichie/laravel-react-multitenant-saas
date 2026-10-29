import { screen, waitFor, within } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { acme, jsonResponse, logIn, mockApi, organizationList, renderApp } from '@/test/utils'

// With the keyboard, as a keyboard user would: in jsdom, moving the pointer into a
// submenu counts as leaving its trigger, which closes it before the click lands.
async function chooseAppearance(user: ReturnType<typeof renderApp>['user'], option: 'Light' | 'Dark' | 'System') {
  await user.click(await screen.findByRole('button', { name: 'Account: Ada Lovelace' }))
  ;(await screen.findByRole('menuitem', { name: 'Appearance' })).focus()
  await user.keyboard('{ArrowRight}')
  ;(await screen.findByRole('menuitemradio', { name: option })).focus()
  await user.keyboard('{Enter}')
}

describe('AppLayout', () => {
  it('shows who is logged in in the account menu', async () => {
    logIn()
    mockApi({ 'GET /api/organizations': () => jsonResponse(organizationList([acme])) })
    const { user } = renderApp('/')

    await user.click(await screen.findByRole('button', { name: 'Account: Ada Lovelace' }))

    const menu = await screen.findByRole('menu')
    expect(within(menu).getByText('ada@example.com')).toBeInTheDocument()
    expect(within(menu).getByRole('menuitem', { name: 'Log out' })).toBeInTheDocument()
  })

  it('switches to dark mode and back from the account menu', async () => {
    logIn()
    mockApi({ 'GET /api/organizations': () => jsonResponse(organizationList([acme])) })
    const { user } = renderApp('/')

    await chooseAppearance(user, 'Dark')

    await waitFor(() => expect(document.documentElement).toHaveClass('dark'))
    expect(localStorage.getItem('theme')).toBe('dark')

    await chooseAppearance(user, 'Light')

    await waitFor(() => expect(document.documentElement).not.toHaveClass('dark'))
  })

  it('marks the current page in the navigation', async () => {
    logIn()
    mockApi({ 'GET /api/organizations': () => jsonResponse(organizationList([acme])) })
    renderApp('/')

    expect(await screen.findByRole('link', { name: 'Dashboard' })).toHaveAttribute('aria-current', 'page')
  })

  it('remembers a collapsed sidebar', async () => {
    document.cookie = 'sidebar_state=false; path=/'
    logIn()
    mockApi({ 'GET /api/organizations': () => jsonResponse(organizationList([acme])) })
    renderApp('/')

    await screen.findByRole('link', { name: 'Dashboard' })
    expect(document.querySelector('[data-slot="sidebar"][data-state]')).toHaveAttribute('data-state', 'collapsed')
  })
})
