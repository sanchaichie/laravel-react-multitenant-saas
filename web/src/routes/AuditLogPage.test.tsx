import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { acme, ada, beta, jsonResponse, logIn, memberRole, mockApi, organizationList, renderApp } from '@/test/utils'
import { meMember, mia } from '@/test/tasks'
import type { AuditActionInfo, AuditLog, CursorPage, PermissionInfo } from '@/types/api'

type Handler = (init: RequestInit, url: URL) => Response

const actions: AuditActionInfo[] = [
  { value: 'member.invited', label: 'Invited someone', group: 'Members' },
  { value: 'role.updated', label: 'Edited a role', group: 'Roles' },
  { value: 'task.created', label: 'Created a task', group: 'Tasks' },
  { value: 'task.updated', label: 'Edited a task', group: 'Tasks' },
]

const catalogue: PermissionInfo[] = [
  { value: 'tasks.create', label: 'Create tasks, and edit or delete their own', group: 'Tasks' },
  { value: 'tasks.manage', label: 'Edit and delete any task', group: 'Tasks' },
]

let nextId = 500

function entry(overrides: Partial<AuditLog> = {}): AuditLog {
  return {
    id: nextId++,
    action: 'task.created',
    action_label: 'Created a task',
    actor: { id: ada.id, name: ada.name },
    subject: { type: 'task', id: 1, label: 'Write docs' },
    changes: {},
    metadata: {},
    created_at: '2026-10-29T14:05:00.000000Z',
    ...overrides,
  }
}

function page(data: AuditLog[], nextCursor: string | null = null): CursorPage<AuditLog> {
  return { data, meta: { next_cursor: nextCursor, prev_cursor: null, per_page: 25, path: '/api/audit-logs' } }
}

function api(overrides: Record<string, Handler> = {}) {
  return mockApi({
    'GET /api/organizations': () => jsonResponse(organizationList([acme, beta])),
    'GET /api/audit-log/actions': () => jsonResponse({ data: actions }),
    'GET /api/permissions': () => jsonResponse({ data: catalogue }),
    'GET /api/members': () => jsonResponse({ data: [meMember, mia] }),
    'GET /api/audit-logs': () => jsonResponse(page([])),
    ...overrides,
  })
}

describe('AuditLogPage', () => {
  it('lists entries newest first and loads more with the cursor', async () => {
    logIn()
    const fetchMock = api({
      'GET /api/audit-logs': (_init, url) =>
        url.searchParams.get('cursor') === 'abc'
          ? jsonResponse(page([entry({ subject: { type: 'task', id: 2, label: 'Older task' } })]))
          : jsonResponse(page([entry({ subject: { type: 'task', id: 1, label: 'Newer task' } })], 'abc')),
    })
    const { user } = renderApp('/audit-log')

    expect(await screen.findByText('Newer task')).toBeInTheDocument()
    expect(screen.getByText('Oct 29, 2026, 2:05 PM UTC')).toBeInTheDocument()

    await user.click(screen.getByRole('button', { name: 'Load more' }))

    expect(await screen.findByText('Older task')).toBeInTheDocument()
    expect(screen.getByText('Newer task')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Load more' })).not.toBeInTheDocument()
    const requests = fetchMock.mock.calls.map(([url]) => String(url)).filter((url) => url.includes('/api/audit-logs'))
    expect(requests.at(-1)).toContain('cursor=abc')
  })

  it('doesn\'t repeat the name of someone who joined or left', async () => {
    logIn()
    api({
      'GET /api/audit-logs': () =>
        jsonResponse(
          page([
            entry({ action: 'member.joined', action_label: 'Joined', actor: { id: 7, name: 'Mia Member' }, subject: { type: 'member', id: 7, label: 'Mia Member' } }),
            entry({ action: 'member.removed', action_label: 'Removed a member', subject: { type: 'member', id: 7, label: 'Mia Member' } }),
          ]),
        ),
    })
    renderApp('/audit-log')

    const [joined, removed] = within(await screen.findByRole('list', { name: 'Audit log entries' })).getAllByRole('listitem')
    expect(joined).toHaveTextContent(/^Oct 29, 2026, 2:05 PM UTCMia Member joined$/)
    expect(removed).toHaveTextContent('Ada Lovelace removed a member: Mia Member')
  })

  it('shows what changed as text, by label and name', async () => {
    logIn()
    api({
      'GET /api/audit-logs': () =>
        jsonResponse(
          page([
            entry({
              action: 'task.updated',
              action_label: 'Edited a task',
              subject: { type: 'task', id: 1, label: '<b>Launch</b>' },
              changes: {
                title: { old: 'Launch', new: '<b>Launch</b>' },
                status: { old: 'todo', new: 'in_progress' },
                assignee: { old: null, new: { id: 7, name: 'Mia Member' } },
              },
            }),
            entry({
              action: 'role.updated',
              action_label: 'Edited a role',
              subject: { type: 'role', id: 3, label: 'Member' },
              changes: { permissions: { old: ['tasks.create'], new: ['tasks.manage'] } },
            }),
          ]),
        ),
    })
    const { user } = renderApp('/audit-log')

    const [taskRow, roleRow] = within(await screen.findByRole('list', { name: 'Audit log entries' })).getAllByRole('listitem')
    await user.click(within(taskRow!).getByRole('button', { name: 'Details' }))

    // Escaped, never markup.
    expect(within(taskRow!).getAllByText('<b>Launch</b>').length).toBeGreaterThan(0)
    expect(taskRow!.querySelector('b')).toBeNull()
    expect(within(taskRow!).getByText('To do')).toBeInTheDocument()
    expect(within(taskRow!).getByText('In progress')).toBeInTheDocument()
    expect(within(taskRow!).getByText('Mia Member')).toBeInTheDocument()

    await user.click(within(roleRow!).getByRole('button', { name: 'Details' }))
    expect(within(roleRow!).getByText('Edit and delete any task')).toBeInTheDocument()
    expect(within(roleRow!).getByText('Create tasks, and edit or delete their own')).toBeInTheDocument()
    expect(within(roleRow!).getByText('Added:')).toBeInTheDocument()
    expect(within(roleRow!).getByText('Removed:')).toBeInTheDocument()
  })

  it('names Stripe for the plan changes and payments it reported', async () => {
    logIn()
    api({
      'GET /api/audit-logs': () =>
        jsonResponse(
          page([
            entry({
              action: 'billing.plan_changed',
              action_label: 'Changed the plan',
              actor: null,
              subject: { type: 'organization', id: acme.id, label: 'Acme' },
              changes: { plan: { old: 'Free', new: 'Pro' } },
              metadata: { source: 'stripe' },
            }),
            entry({
              action: 'billing.credits_purchased',
              action_label: 'Bought AI credits',
              subject: { type: 'organization', id: acme.id, label: 'Acme' },
              metadata: { source: 'stripe', pack: '$10 of AI credits', amount_usd: 10 },
            }),
          ]),
        ),
    })
    const { user } = renderApp('/audit-log')

    const [plan, credits] = within(await screen.findByRole('list', { name: 'Audit log entries' })).getAllByRole('listitem')
    expect(plan).toHaveTextContent('Stripe changed the plan: Acme')
    await user.click(within(plan!).getByRole('button', { name: 'Details' }))
    expect(within(plan!).getByText('Plan')).toBeInTheDocument()
    expect(within(plan!).getByText('Free')).toBeInTheDocument()
    expect(within(plan!).getByText('Pro')).toBeInTheDocument()
    expect(within(plan!).getByText('Reported by Stripe')).toBeInTheDocument()

    // The buyer, while still a member.
    expect(credits).toHaveTextContent(`${ada.name} bought AI credits: Acme`)
    await user.click(within(credits!).getByRole('button', { name: 'Details' }))
    expect(within(credits!).getByText('$10 of AI credits')).toBeInTheDocument()
    expect(within(credits!).getByText('$10.00')).toBeInTheDocument()
  })

  it('keeps filters in the URL and sends them to the API', async () => {
    logIn()
    const fetchMock = api()
    const { router, user } = renderApp('/audit-log')

    expect(await screen.findByText('Nothing recorded yet')).toBeInTheDocument()

    screen.getByRole('combobox', { name: 'Action' }).focus()
    await user.keyboard('{Enter}')
    await user.click(await screen.findByRole('option', { name: 'Edited a task' }))
    fireEvent.change(screen.getByLabelText('From'), { target: { value: '2026-10-01' } })

    await waitFor(() => expect(router.state.location.search).toBe('?action=task.updated&from=2026-10-01'))
    expect(await screen.findByText('No entries match these filters')).toBeInTheDocument()
    const last = String(fetchMock.mock.calls.map(([url]) => String(url)).filter((url) => url.includes('/api/audit-logs')).at(-1))
    expect(last).toContain('action=task.updated')
    expect(last).toContain('from=2026-10-01')

    await user.click(screen.getAllByRole('button', { name: 'Clear filters' })[0]!)
    await waitFor(() => expect(router.state.location.search).toBe(''))
  })

  it('drops an action the API doesn\'t know before asking for the list', async () => {
    logIn()
    const fetchMock = api()
    const { router } = renderApp('/audit-log?action=task.archived&actor=7')

    await waitFor(() => expect(router.state.location.search).toBe('?actor=7'))
    await screen.findByText('No entries match these filters')
    const requests = fetchMock.mock.calls.map(([url]) => String(url)).filter((url) => url.includes('/api/audit-logs'))
    expect(requests.every((url) => !url.includes('archived'))).toBe(true)
  })

  it('shows an error with Retry', async () => {
    logIn()
    let fail = true
    api({
      'GET /api/audit-logs': () => (fail ? jsonResponse({ message: 'Server error.' }, 500) : jsonResponse(page([entry()]))),
    })
    const { user } = renderApp('/audit-log')

    expect(await screen.findByRole('heading', { name: 'Could not load the audit log' }, { timeout: 3000 })).toBeInTheDocument()
    fail = false
    await user.click(screen.getByRole('button', { name: 'Retry' }))

    expect(await screen.findByText('Write docs')).toBeInTheDocument()
  })

  it('tells a role without audit_log.view it has no access, and hides the link', async () => {
    logIn({ ...ada, current_organization: { ...acme, role: memberRole, permissions: ['tasks.create'] } })
    const fetchMock = api()
    renderApp('/audit-log')

    expect(await screen.findByText('You don\'t have access to the audit log')).toBeInTheDocument()
    expect(screen.queryByRole('link', { name: 'Audit log' })).not.toBeInTheDocument()
    expect(fetchMock.mock.calls.some(([url]) => String(url).includes('/api/audit-log'))).toBe(false)
  })

  it('shows the no-access message when the API says 403', async () => {
    logIn()
    api({ 'GET /api/audit-logs': () => jsonResponse({ message: 'This action is unauthorized.' }, 403) })
    renderApp('/audit-log')

    expect(await screen.findByText('You don\'t have access to the audit log', undefined, { timeout: 3000 })).toBeInTheDocument()
    // The link shows for roles with the permission.
    expect(screen.getByRole('link', { name: 'Audit log' })).toBeInTheDocument()
  })
})
