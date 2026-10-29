import { screen, waitFor, within } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { queryClient } from '@/lib/queryClient'
import { useAuth } from '@/stores/auth'
import { acme, jsonResponse, logIn, mockApi, organizationList, renderApp, sentBody } from '@/test/utils'
import type { Organization } from '@/types/api'

const gamma: Organization = { id: 30, name: 'Gamma', role: 'owner', created_at: '2026-10-29T12:00:00.000000Z' }

async function openCreateDialog(user: ReturnType<typeof renderApp>['user']) {
  await user.click(await screen.findByRole('button', { name: 'Organization: Acme' }))
  await user.click(await screen.findByRole('menuitem', { name: 'Create organization' }))
  return screen.findByRole('dialog', { name: 'Create organization' })
}

describe('CreateOrganizationDialog', () => {
  it('asks for a name before sending anything', async () => {
    logIn()
    const fetchMock = mockApi({ 'GET /api/organizations': () => jsonResponse(organizationList([acme])) })
    const { user } = renderApp('/')

    const dialog = await openCreateDialog(user)
    await user.type(within(dialog).getByLabelText('Name'), '   ')
    await user.click(within(dialog).getByRole('button', { name: 'Create' }))

    expect(await within(dialog).findByText('Enter a name.')).toBeInTheDocument()
    expect(within(dialog).getByLabelText('Name')).toHaveAccessibleDescription('Enter a name.')
    expect(fetchMock.mock.calls.every(([, init]) => (init?.method ?? 'GET') === 'GET')).toBe(true)
  })

  it('shows the API\'s error on the field', async () => {
    logIn()
    mockApi({
      'GET /api/organizations': () => jsonResponse(organizationList([acme])),
      'POST /api/organizations': () =>
        jsonResponse({ message: 'Invalid.', errors: { name: ['The name field must not be greater than 255 characters.'] } }, 422),
    })
    const { user } = renderApp('/')

    const dialog = await openCreateDialog(user)
    await user.type(within(dialog).getByLabelText('Name'), 'Gamma')
    await user.click(within(dialog).getByRole('button', { name: 'Create' }))

    expect(await within(dialog).findByText('The name field must not be greater than 255 characters.')).toBeInTheDocument()
  })

  it('creates it, makes it current and closes', async () => {
    logIn()
    queryClient.setQueryData(['org', acme.id, 'tasks'], ['Acme task'])
    const fetchMock = mockApi({
      'GET /api/organizations': () => jsonResponse(organizationList([acme])),
      'POST /api/organizations': () => jsonResponse({ data: gamma }, 201),
    })
    const { user } = renderApp('/')

    const dialog = await openCreateDialog(user)
    await user.type(within(dialog).getByLabelText('Name'), '  Gamma ')
    await user.click(within(dialog).getByRole('button', { name: 'Create' }))

    expect(await screen.findByText('Organization created')).toBeInTheDocument()
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Organization: Gamma' })).toBeInTheDocument()
    expect(useAuth.getState().user?.current_organization).toEqual(gamma)
    expect(queryClient.getQueryData(['org', acme.id, 'tasks'])).toBeUndefined()
    const post = fetchMock.mock.calls.find(([, init]) => init?.method === 'POST')
    expect(sentBody(post?.[1] ?? {})).toEqual({ name: 'Gamma' })
  })

  it('gives focus back to the switcher when closed', async () => {
    logIn()
    mockApi({ 'GET /api/organizations': () => jsonResponse(organizationList([acme])) })
    const { user } = renderApp('/')

    await openCreateDialog(user)
    await user.keyboard('{Escape}')

    await waitFor(() => expect(screen.getByRole('button', { name: 'Organization: Acme' })).toHaveFocus())
  })

  it('starts empty each time it opens', async () => {
    logIn()
    mockApi({ 'GET /api/organizations': () => jsonResponse(organizationList([acme])) })
    const { user } = renderApp('/')

    let dialog = await openCreateDialog(user)
    await user.type(within(dialog).getByLabelText('Name'), 'Half typed')
    await user.click(within(dialog).getByRole('button', { name: 'Cancel' }))

    dialog = await openCreateDialog(user)
    expect(within(dialog).getByLabelText('Name')).toHaveValue('')
  })
})
