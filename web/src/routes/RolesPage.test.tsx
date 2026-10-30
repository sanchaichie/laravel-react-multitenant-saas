import { screen, waitFor, within } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { acme, ada, adminRole, beta, jsonResponse, logIn, memberRole, mockApi, organizationList, ownerRole, renderApp, sentBody } from '@/test/utils'
import type { PermissionInfo, RoleDetails } from '@/types/api'

type Handler = (init: RequestInit, url: URL) => Response

const catalogue: PermissionInfo[] = [
  { value: 'tasks.create', label: 'Create tasks, and edit or delete their own', group: 'Tasks' },
  { value: 'tasks.manage', label: 'Edit and delete any task', group: 'Tasks' },
  { value: 'members.invite', label: 'Invite people and manage invitations', group: 'Members' },
  { value: 'members.manage', label: 'Change roles and remove members', group: 'Members' },
  { value: 'roles.manage', label: 'Create, edit and delete roles', group: 'Roles' },
]

const editable = { update: true, delete: true }
const locked = { update: false, delete: false }

const roles: RoleDetails[] = [
  { ...ownerRole, permissions: catalogue.map((p) => p.value), members_count: 1, can: locked },
  { ...adminRole, permissions: ['tasks.create', 'tasks.manage', 'members.invite', 'members.manage'], members_count: 2, can: editable },
  { ...memberRole, permissions: ['tasks.create'], members_count: 5, can: editable },
  { id: 4, name: 'Viewer', is_owner: false, permissions: [], members_count: 0, can: editable },
]

function api(overrides: Record<string, Handler> = {}) {
  return mockApi({
    'GET /api/organizations': () => jsonResponse(organizationList([acme, beta])),
    'GET /api/roles': () => jsonResponse({ data: roles }),
    'GET /api/permissions': () => jsonResponse({ data: catalogue }),
    ...overrides,
  })
}

function row(name: string) {
  const found = screen.getAllByRole('row').find((r) => within(r).queryByText(name, { selector: 'span' }))
  if (!found) throw new Error(`No row for ${name}`)
  return found
}

describe('RolesPage', () => {
  it('lists the roles with their members and what they allow', async () => {
    logIn()
    api()
    renderApp('/roles')

    expect(await screen.findByRole('heading', { name: 'Roles', level: 1 })).toBeInTheDocument()
    await screen.findByRole('table')
    expect(within(row('Owner')).getByText('Built in')).toBeInTheDocument()
    expect(within(row('Owner')).getAllByText('Everything, including permissions added later')[0]).toBeInTheDocument()
    expect(within(row('Admin')).getAllByText('Tasks: create, manage · Members: invite, manage')[0]).toBeInTheDocument()
    expect(within(row('Member')).getAllByText('5 members')[0]).toBeInTheDocument()
    expect(within(row('Viewer')).getAllByText('Sees tasks and members only')[0]).toBeInTheDocument()
    // The owner role has no controls, whatever you are.
    expect(within(row('Owner')).queryByRole('button')).not.toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Roles' })).toHaveAttribute('aria-current', 'page')
  })

  it('creates a role from the checkboxes', async () => {
    logIn()
    const fetchMock = api({
      'POST /api/roles': (init) => jsonResponse({ data: { id: 5, is_owner: false, members_count: 0, can: editable, ...(sentBody(init) as object) } }, 201),
    })
    const { user } = renderApp('/roles')

    await user.click(await screen.findByRole('button', { name: 'New role' }))
    const dialog = await screen.findByRole('dialog', { name: 'New role' })
    // The name is required.
    await user.click(within(dialog).getByRole('button', { name: 'Create role' }))
    expect(await within(dialog).findByText('Enter a name.')).toBeInTheDocument()

    await user.type(within(dialog).getByLabelText('Name'), 'Reviewer')
    await user.click(within(dialog).getByRole('checkbox', { name: 'Edit and delete any task' }))
    await user.click(within(dialog).getByRole('checkbox', { name: 'Invite people and manage invitations' }))
    await user.click(within(dialog).getByRole('checkbox', { name: 'Invite people and manage invitations' }))
    await user.click(within(dialog).getByRole('button', { name: 'Create role' }))

    expect(await screen.findByText('Role created')).toBeInTheDocument()
    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument())
    const post = fetchMock.mock.calls.find(([, init]) => init?.method === 'POST')
    expect(sentBody(post?.[1] ?? {})).toEqual({ name: 'Reviewer', permissions: ['tasks.manage'] })
    // The list is fetched again.
    await waitFor(() => expect(fetchMock.mock.calls.filter(([url]) => String(url).endsWith('/api/roles'))).toHaveLength(3))
  })

  it('disables permissions your role doesn\'t have', async () => {
    logIn({ ...ada, current_organization: { ...acme, role: { id: 6, name: 'Role manager', is_owner: false }, permissions: ['roles.manage', 'tasks.create'] } })
    api({ 'GET /api/roles': () => jsonResponse({ data: roles.map((role) => (role.id === 4 ? role : { ...role, can: locked })) }) })
    const { user } = renderApp('/roles')

    await user.click(await screen.findByRole('button', { name: 'New role' }))
    const dialog = await screen.findByRole('dialog', { name: 'New role' })

    expect(within(dialog).getByRole('checkbox', { name: 'Create tasks, and edit or delete their own' })).toBeEnabled()
    const invite = within(dialog).getByRole('checkbox', { name: 'Invite people and manage invitations' })
    expect(invite).toBeDisabled()
    expect(invite).toHaveAccessibleDescription("Your role doesn't have this.")
  })

  it('edits a role, filled with its name and permissions', async () => {
    logIn()
    const fetchMock = api({
      'PATCH /api/roles/3': (init) => jsonResponse({ data: { ...roles[2], ...(sentBody(init) as object) } }),
    })
    const { user } = renderApp('/roles')

    await user.click(await screen.findByRole('button', { name: 'Edit Member' }))
    const dialog = await screen.findByRole('dialog', { name: 'Edit Member' })
    expect(within(dialog).getByLabelText('Name')).toHaveValue('Member')
    expect(within(dialog).getByRole('checkbox', { name: 'Create tasks, and edit or delete their own' })).toBeChecked()

    await user.click(within(dialog).getByRole('checkbox', { name: 'Invite people and manage invitations' }))
    await user.click(within(dialog).getByRole('button', { name: 'Save' }))

    expect(await screen.findByText('Role saved')).toBeInTheDocument()
    const patch = fetchMock.mock.calls.find(([, init]) => init?.method === 'PATCH')
    expect(sentBody(patch?.[1] ?? {})).toEqual({ name: 'Member', permissions: ['tasks.create', 'members.invite'] })
    // Focus goes back to the button that opened it.
    await waitFor(() => expect(screen.getByRole('button', { name: 'Edit Member' })).toHaveFocus())
  })

  it('shows the API\'s name error on the field', async () => {
    logIn()
    api({
      'POST /api/roles': () => jsonResponse({ message: 'Invalid.', errors: { name: ['Another role already has this name.'] } }, 422),
    })
    const { user } = renderApp('/roles')

    await user.click(await screen.findByRole('button', { name: 'New role' }))
    const dialog = await screen.findByRole('dialog', { name: 'New role' })
    await user.type(within(dialog).getByLabelText('Name'), 'admin')
    await user.click(within(dialog).getByRole('button', { name: 'Create role' }))

    expect(await within(dialog).findByText('Another role already has this name.')).toBeInTheDocument()
    expect(within(dialog).getByLabelText('Name')).toHaveFocus()
  })

  it('says why a role in use can\'t be deleted', async () => {
    logIn()
    api({
      'DELETE /api/roles/3': () =>
        jsonResponse({ message: '5 members have this role. Give them another role first.', errors: { role: ['5 members have this role. Give them another role first.'] } }, 422),
    })
    const { user } = renderApp('/roles')

    await user.click(await screen.findByRole('button', { name: 'Delete Member' }))
    const dialog = await screen.findByRole('alertdialog', { name: 'Delete Member?' })
    expect(within(dialog).getByText('5 members still have this role. Give them another role first.')).toBeInTheDocument()
    await user.click(within(dialog).getByRole('button', { name: 'Delete' }))

    expect(await screen.findByText('Could not delete Member')).toBeInTheDocument()
    expect(screen.getByText('5 members have this role. Give them another role first.')).toBeInTheDocument()
  })

  it('deletes an unused role', async () => {
    logIn()
    let current = roles
    api({
      'GET /api/roles': () => jsonResponse({ data: current }),
      'DELETE /api/roles/4': () => {
        current = roles.filter((role) => role.id !== 4)
        return jsonResponse(null, 204)
      },
    })
    const { user } = renderApp('/roles')

    await user.click(await screen.findByRole('button', { name: 'Delete Viewer' }))
    await user.click(within(await screen.findByRole('alertdialog')).getByRole('button', { name: 'Delete' }))

    expect(await screen.findByText('Viewer was deleted')).toBeInTheDocument()
    await waitFor(() => expect(screen.queryByText('Viewer', { selector: 'span' })).not.toBeInTheDocument())
  })

  it('shows the roles but no controls without roles.manage', async () => {
    logIn({ ...ada, current_organization: { ...acme, role: memberRole, permissions: ['tasks.create'] } })
    api({ 'GET /api/roles': () => jsonResponse({ data: roles.map((role) => ({ ...role, can: locked })) }) })
    renderApp('/roles')

    await screen.findByRole('table')
    expect(screen.queryByRole('button', { name: 'New role' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /^(Edit|Delete) / })).not.toBeInTheDocument()
  })

  it('shows an error state when the roles can\'t be loaded', async () => {
    logIn()
    api({ 'GET /api/roles': () => jsonResponse({ message: 'Server Error' }, 500) })
    renderApp('/roles')

    expect(await screen.findByRole('heading', { name: 'Could not load the roles' }, { timeout: 3000 })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Retry' })).toBeInTheDocument()
  })
})
