import { screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it, vi } from 'vitest'
import { acme } from '@/test/fixtures'
import { renderWithClient } from '@/test/render'
import { OrganizationSwitcher } from './OrganizationSwitcher'

const switchOrganization = vi.hoisted(() => vi.fn(async () => undefined))

vi.mock('next/navigation', () => ({ usePathname: () => '/tasks/42' }))
vi.mock('./actions', () => ({ switchOrganization }))

const beta = { ...acme, id: 20, name: 'Beta', role: { id: 9, name: 'Member', is_owner: false } }

describe('the organization switcher', () => {
  it('opens a menu of organizations and switches from the current page', async () => {
    const user = userEvent.setup()
    const { queryClient } = renderWithClient(<OrganizationSwitcher current={acme} organizations={[acme, beta]} />)
    queryClient.setQueryData(['org', acme.id, 'tasks', 'detail', 42, 'chat'], { data: [] })

    await user.click(screen.getByRole('button', { name: 'Organization: Acme' }))
    await user.click(await screen.findByRole('menuitem', { name: /Beta/ }))

    expect(switchOrganization).toHaveBeenCalledWith(20, '/tasks/42')
    // The old organization's client data is gone.
    await vi.waitFor(() => expect(queryClient.getQueryCache().findAll({ queryKey: ['org'] })).toHaveLength(0))
  })

  it('does nothing when choosing the current one', async () => {
    switchOrganization.mockClear()
    const user = userEvent.setup()
    renderWithClient(<OrganizationSwitcher current={acme} organizations={[acme, beta]} />)

    await user.click(screen.getByRole('button', { name: 'Organization: Acme' }))
    await user.click(await screen.findByRole('menuitem', { name: /Acme/ }))

    expect(switchOrganization).not.toHaveBeenCalled()
  })
})
