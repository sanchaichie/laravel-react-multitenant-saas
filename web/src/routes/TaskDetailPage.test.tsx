import { screen, waitFor, within } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { makeTask, meMember, mia, noSummary, taskPage } from '@/test/tasks'
import { acme, ada, beta, memberRole, jsonResponse, logIn, mockApi, organizationList, renderApp, sentBody } from '@/test/utils'
import type { Task } from '@/types/api'

type Handler = (init: RequestInit, url: URL) => Response

const task = makeTask({
  id: 42,
  title: 'Write onboarding doc',
  description: 'Line one\nLine two',
  priority: 'high',
  due_date: '2026-10-25',
  is_overdue: true,
  assignee: { id: mia.id, name: mia.name },
})

function api(overrides: Record<string, Handler> = {}) {
  return mockApi({
    'GET /api/organizations': () => jsonResponse(organizationList([acme, beta])),
    'GET /api/members': () => jsonResponse({ data: [meMember, mia] }),
    'GET /api/tasks/42': () => jsonResponse({ data: task }),
    'GET /api/tasks/42/summary': () => jsonResponse(noSummary),
    ...overrides,
  })
}

describe('TaskDetailPage', () => {
  it('shows the task', async () => {
    logIn()
    api()
    renderApp('/tasks/42')

    expect(await screen.findByRole('heading', { name: 'Write onboarding doc', level: 1 })).toBeInTheDocument()
    expect(screen.getByText(/Line one\s+Line two/)).toBeInTheDocument()
    expect(screen.getByText('High')).toBeInTheDocument()
    expect(screen.getByText('Mia Member')).toBeInTheDocument()
    expect(screen.getByText('Oct 25, 2026')).toBeInTheDocument()
    expect(screen.getByText('Overdue')).toBeInTheDocument()
    expect(within(screen.getByRole('main')).getByText('Ada Lovelace')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Edit' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Delete' })).toBeInTheDocument()
    // Tasks stays the active section on a task's page.
    expect(screen.getByRole('link', { name: 'Tasks', current: 'page' })).toBeInTheDocument()
  })

  it('says not found on a 404, without retrying', async () => {
    logIn()
    const fetchMock = api({ 'GET /api/tasks/43': () => jsonResponse({ message: 'Not found.' }, 404) })
    renderApp('/tasks/43')

    expect(await screen.findByRole('heading', { name: 'Task not found' })).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Back to tasks' })).toHaveAttribute('href', '/tasks')
    // A 404 is an answer: no retry.
    expect(fetchMock.mock.calls.filter(([url]) => String(url).endsWith('/api/tasks/43'))).toHaveLength(1)
  })

  it('says not found for a malformed id without asking the API', async () => {
    logIn()
    const fetchMock = api()
    renderApp('/tasks/abc')

    expect(await screen.findByRole('heading', { name: 'Task not found' })).toBeInTheDocument()
    expect(fetchMock.mock.calls.some(([url]) => String(url).includes('/api/tasks/'))).toBe(false)
  })

  it('hides Edit, Delete and the status select per `can`', async () => {
    logIn({ ...ada, current_organization: { ...acme, role: memberRole, permissions: ['tasks.create'] } })
    api({ 'GET /api/tasks/42': () => jsonResponse({ data: { ...task, can: { update: false, delete: false, write_with_ai: false, chat: false } } }) })
    renderApp('/tasks/42')

    await screen.findByRole('heading', { name: 'Write onboarding doc' })
    expect(screen.queryByRole('button', { name: 'Edit' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Delete' })).not.toBeInTheDocument()
    expect(screen.queryByRole('combobox', { name: 'Status' })).not.toBeInTheDocument()
    expect(screen.getByText('To do')).toBeInTheDocument()
  })

  it('changes the status from the select', async () => {
    logIn()
    const fetchMock = api({
      'PATCH /api/tasks/42': () => jsonResponse({ data: { ...task, status: 'done', is_overdue: false } }),
    })
    const { user } = renderApp('/tasks/42')

    const select = await screen.findByRole('combobox', { name: 'Status' })
    select.focus()
    await user.keyboard('{Enter}')
    await user.click(await screen.findByRole('option', { name: 'Done' }))

    expect(await screen.findByText('Marked done')).toBeInTheDocument()
    await waitFor(() => expect(screen.getByRole('combobox', { name: 'Status' })).toHaveTextContent('Done'))
    expect(screen.queryByText('Overdue')).not.toBeInTheDocument()
    const patch = fetchMock.mock.calls.find(([, init]) => init?.method === 'PATCH')
    expect(sentBody(patch?.[1] ?? {})).toEqual({ status: 'done' })
  })

  it('edits in the dialog, filled with the task', async () => {
    logIn()
    let current: Task = task
    const fetchMock = api({
      'GET /api/tasks/42': () => jsonResponse({ data: current }),
      'PATCH /api/tasks/42': (init) => {
        current = { ...current, ...(sentBody(init) as Partial<Task>), assignee: null }
        return jsonResponse({ data: current })
      },
    })
    const { user } = renderApp('/tasks/42')

    const edit = await screen.findByRole('button', { name: 'Edit' })
    await user.click(edit)
    const dialog = await screen.findByRole('dialog', { name: 'Edit task' })
    expect(within(dialog).getByLabelText('Title')).toHaveValue('Write onboarding doc')
    expect(within(dialog).getByLabelText('Due date')).toHaveValue('2026-10-25')
    expect(within(dialog).getByRole('combobox', { name: 'Assignee' })).toHaveTextContent('Mia Member')

    await user.clear(within(dialog).getByLabelText('Title'))
    await user.type(within(dialog).getByLabelText('Title'), 'Rewrite onboarding doc')
    within(dialog).getByRole('combobox', { name: 'Assignee' }).focus()
    await user.keyboard('{Enter}')
    await user.click(await screen.findByRole('option', { name: 'Unassigned' }))
    await user.click(within(dialog).getByRole('button', { name: 'Save' }))

    expect(await screen.findByRole('heading', { name: 'Rewrite onboarding doc', level: 1 })).toBeInTheDocument()
    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument())
    // Focus goes back to the button that opened the dialog.
    await waitFor(() => expect(screen.getByRole('button', { name: 'Edit' })).toHaveFocus())
    const patch = fetchMock.mock.calls.find(([, init]) => init?.method === 'PATCH')
    // Only what changed: the status or due date someone else may have changed meanwhile isn't sent back.
    expect(sentBody(patch?.[1] ?? {})).toEqual({ title: 'Rewrite onboarding doc', assignee_id: null })
  })

  it('deletes after confirming, then goes back to the list', async () => {
    logIn()
    const fetchMock = api({
      'DELETE /api/tasks/42': () => jsonResponse(null, 204),
      'GET /api/tasks': () => jsonResponse(taskPage([])),
    })
    const { router, user } = renderApp('/tasks/42')

    await user.click(await screen.findByRole('button', { name: 'Delete' }))
    const dialog = await screen.findByRole('alertdialog', { name: 'Delete this task?' })
    await user.click(within(dialog).getByRole('button', { name: 'Delete' }))

    expect(await screen.findByText('Task deleted')).toBeInTheDocument()
    await waitFor(() => expect(router.state.location.pathname).toBe('/tasks'))
    expect(await screen.findByRole('heading', { name: 'No tasks yet' })).toBeInTheDocument()
    // The deleted task wasn't asked for again (no "not found" flash).
    expect(fetchMock.mock.calls.filter(([url, init]) => String(url).endsWith('/api/tasks/42') && (init?.method ?? 'GET') === 'GET')).toHaveLength(1)
  })

  it('goes back to the list when you switch organization, without asking the new one for the task', async () => {
    logIn()
    const fetchMock = api({
      [`POST /api/organizations/${beta.id}/switch`]: () => jsonResponse({ data: beta }),
      'GET /api/tasks': () => jsonResponse(taskPage([])),
    })
    const { router, user } = renderApp('/tasks/42')

    await screen.findByRole('heading', { name: 'Write onboarding doc' })
    await user.click(screen.getByRole('button', { name: 'Organization: Acme' }))
    await user.click(await screen.findByRole('menuitemcheckbox', { name: /Beta/ }))

    await waitFor(() => expect(router.state.location.pathname).toBe('/tasks'))
    expect(await screen.findByText('Everything to do in Beta.')).toBeInTheDocument()
    expect(fetchMock.mock.calls.filter(([url]) => String(url).endsWith('/api/tasks/42'))).toHaveLength(1)
  })
})
