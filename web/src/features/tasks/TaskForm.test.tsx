import { screen, waitFor, within } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { makeTask, meMember, mia, taskPage } from '@/test/tasks'
import { acme, beta, jsonResponse, logIn, mockApi, organizationList, renderApp } from '@/test/utils'

type Handler = (init: RequestInit, url: URL) => Response

function api(overrides: Record<string, Handler> = {}) {
  return mockApi({
    'GET /api/organizations': () => jsonResponse(organizationList([acme, beta])),
    'GET /api/members': () => jsonResponse({ data: [meMember, mia] }),
    'GET /api/tasks': () => jsonResponse(taskPage([])),
    ...overrides,
  })
}

async function openNewTask(user: ReturnType<typeof renderApp>['user']) {
  await screen.findByRole('heading', { name: 'No tasks yet' })
  await user.click(screen.getAllByRole('button', { name: 'New task' })[0])
  return screen.findByRole('dialog', { name: 'New task' })
}

describe('TaskForm', () => {
  it('checks the title and description before sending', async () => {
    logIn()
    const fetchMock = api()
    const { user } = renderApp('/tasks')
    const dialog = await openNewTask(user)

    await user.type(within(dialog).getByLabelText('Title'), '   ')
    await user.click(within(dialog).getByRole('button', { name: 'Create task' }))
    expect(await within(dialog).findByText('Enter a title.')).toBeInTheDocument()
    expect(within(dialog).getByLabelText('Title')).toHaveFocus()

    await user.type(within(dialog).getByLabelText('Title'), 'Fine')
    // Pasted, not typed: 5001 keystrokes would be slow.
    await user.click(within(dialog).getByLabelText('Description'))
    await user.paste('a'.repeat(5001))
    await user.click(within(dialog).getByRole('button', { name: 'Create task' }))
    expect(await within(dialog).findByText('At most 5000 characters.')).toBeInTheDocument()

    expect(fetchMock.mock.calls.some(([, init]) => init?.method === 'POST')).toBe(false)
  })

  it('offers Unassigned and every member as assignees, and defaults the rest', async () => {
    logIn()
    api()
    const { user } = renderApp('/tasks')
    const dialog = await openNewTask(user)

    expect(within(dialog).getByRole('combobox', { name: 'Status' })).toHaveTextContent('To do')
    expect(within(dialog).getByRole('combobox', { name: 'Priority' })).toHaveTextContent('Medium')
    const assignee = within(dialog).getByRole('combobox', { name: 'Assignee' })
    expect(assignee).toHaveTextContent('Unassigned')

    assignee.focus()
    await user.keyboard('{Enter}')
    const options = await screen.findAllByRole('option')
    expect(options.map((option) => option.textContent)).toEqual(['Unassigned', 'Ada Lovelace (you)', 'Mia Member'])
  })

  it('keeps an assignee who is no longer in the member list', async () => {
    logIn()
    const gone = makeTask({ id: 5, title: 'Old task', assignee: { id: 99, name: 'Gone Person' } })
    api({ 'GET /api/tasks/5': () => jsonResponse({ data: gone }) })
    const { user } = renderApp('/tasks/5')

    await user.click(await screen.findByRole('button', { name: 'Edit' }))
    const dialog = await screen.findByRole('dialog', { name: 'Edit task' })
    expect(within(dialog).getByRole('combobox', { name: 'Assignee' })).toHaveTextContent('Gone Person')
  })

  it('closes without a request when nothing changed', async () => {
    logIn()
    const task = makeTask({ id: 6, title: 'Same' })
    const fetchMock = api({ 'GET /api/tasks/6': () => jsonResponse({ data: task }) })
    const { user } = renderApp('/tasks/6')

    await user.click(await screen.findByRole('button', { name: 'Edit' }))
    const dialog = await screen.findByRole('dialog', { name: 'Edit task' })
    await user.click(within(dialog).getByRole('button', { name: 'Save' }))

    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument())
    expect(fetchMock.mock.calls.some(([, init]) => init?.method === 'PATCH')).toBe(false)
  })
})
