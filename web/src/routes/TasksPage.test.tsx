import { screen, waitFor, within } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { makeTask, meMember, mia, taskPage } from '@/test/tasks'
import { acme, ada, beta, memberRole, jsonResponse, logIn, mockApi, organizationList, renderApp, sentBody } from '@/test/utils'
import type { Paginated, Task } from '@/types/api'

type Handler = (init: RequestInit, url: URL) => Response | Promise<Response>

function api(list: Handler = () => jsonResponse(taskPage([])), overrides: Record<string, Handler> = {}) {
  return mockApi({
    'GET /api/organizations': () => jsonResponse(organizationList([acme, beta])),
    'GET /api/members': () => jsonResponse({ data: [meMember, mia] }),
    'GET /api/tasks': list,
    ...overrides,
  })
}

/** The query strings GET /api/tasks was called with, in order. */
function listQueries(fetchMock: ReturnType<typeof mockApi>): string[] {
  return fetchMock.mock.calls
    .map(([input]) => new URL(String(input)))
    .filter((url) => url.pathname === '/api/tasks')
    .map((url) => url.search)
}

async function choose(user: ReturnType<typeof renderApp>['user'], select: string, option: string) {
  const trigger = await screen.findByRole('combobox', { name: select })
  trigger.focus()
  await user.keyboard('{Enter}')
  await user.click(await screen.findByRole('option', { name: option }))
}

describe('TasksPage', () => {
  it('lists the tasks, with a Tasks link in the sidebar', async () => {
    logIn()
    api(() =>
      jsonResponse(
        taskPage([
          makeTask({ title: 'Write the docs', status: 'in_progress', priority: 'high', assignee: { id: mia.id, name: mia.name }, due_date: '2026-10-25', is_overdue: true }),
          makeTask({ title: 'Plan launch' }),
        ]),
      ),
    )
    renderApp('/tasks')

    expect(await screen.findByRole('heading', { name: 'Tasks', level: 1 })).toBeInTheDocument()
    const rows = await screen.findAllByRole('row')
    expect(rows).toHaveLength(3)
    expect(within(rows[1]).getByRole('link', { name: 'Write the docs' })).toHaveAttribute('href', expect.stringMatching(/\/tasks\/\d+$/))
    expect(within(rows[1]).getByText('In progress')).toBeInTheDocument()
    expect(within(rows[1]).getByText('High')).toBeInTheDocument()
    expect(within(rows[1]).getAllByText('Mia Member').length).toBeGreaterThan(0)
    expect(within(rows[1]).getByText('Oct 25, 2026')).toBeInTheDocument()
    expect(within(rows[1]).getByText('Overdue')).toBeInTheDocument()
    expect(within(rows[2]).getAllByText('Unassigned').length).toBeGreaterThan(0)
    expect(screen.getByRole('link', { name: 'Tasks' })).toHaveAttribute('aria-current', 'page')
  })

  it('shows an empty state with New task', async () => {
    logIn()
    api()
    renderApp('/tasks')

    expect(await screen.findByRole('heading', { name: 'No tasks yet' })).toBeInTheDocument()
    expect(screen.getAllByRole('button', { name: 'New task' })).toHaveLength(2)
  })

  it('shows an error with Retry', async () => {
    logIn()
    api(() => jsonResponse({ message: 'Server Error' }, 500))
    renderApp('/tasks')

    expect(await screen.findByRole('heading', { name: 'Could not load the tasks' }, { timeout: 3000 })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Retry' })).toBeInTheDocument()
  })

  it('loads the filters from the URL, and sends them to the API', async () => {
    logIn()
    const fetchMock = api(() => jsonResponse(taskPage([])))
    renderApp('/tasks?status=done&assignee=me&overdue=1&search=launch&sort=due&bogus=1&priority=urgent')

    expect(await screen.findByRole('heading', { name: 'No tasks match these filters' })).toBeInTheDocument()
    expect(listQueries(fetchMock)).toEqual(['?status=done&assignee=me&overdue=1&search=launch&sort=due'])
    expect(screen.getByRole('combobox', { name: 'Status' })).toHaveTextContent('Done')
    expect(screen.getByRole('combobox', { name: 'Assignee' })).toHaveTextContent('Assigned to me')
    expect(screen.getByRole('combobox', { name: 'Priority' })).toHaveTextContent('Any priority')
    expect(screen.getByRole('checkbox', { name: 'Overdue' })).toBeChecked()
    expect(screen.getByRole('searchbox', { name: 'Search tasks' })).toHaveValue('launch')
  })

  it('writes filters to the URL and goes back to page 1', async () => {
    logIn()
    const fetchMock = api((_init, url) => jsonResponse(taskPage([makeTask()], { page: Number(url.searchParams.get('page') ?? 1), lastPage: 3 })))
    const { router, user } = renderApp('/tasks?page=2')

    await screen.findByText('Page 2 of 3')
    await choose(user, 'Status', 'In progress')
    await waitFor(() => expect(router.state.location.search).toBe('?status=in_progress'))

    await user.click(screen.getByRole('checkbox', { name: 'Overdue' }))
    await waitFor(() => expect(router.state.location.search).toBe('?status=in_progress&overdue=1'))
    await choose(user, 'Assignee', 'Mia Member')
    await waitFor(() => expect(router.state.location.search).toBe(`?status=in_progress&assignee=${mia.id}&overdue=1`))
    await waitFor(() => expect(listQueries(fetchMock).at(-1)).toBe(`?status=in_progress&assignee=${mia.id}&overdue=1`))
  })

  it('searches once typing pauses', async () => {
    logIn()
    const fetchMock = api()
    const { router, user } = renderApp('/tasks')

    await screen.findByRole('heading', { name: 'No tasks yet' })
    await user.type(screen.getByRole('searchbox', { name: 'Search tasks' }), 'launch')

    await waitFor(() => expect(router.state.location.search).toBe('?search=launch'))
    // One request for the empty list, one for the search: not one per keystroke.
    await waitFor(() => expect(listQueries(fetchMock)).toEqual(['', '?search=launch']))
  })

  it('clears the filters, search box included', async () => {
    logIn()
    api()
    const { router, user } = renderApp('/tasks?search=launch&priority=high&sort=priority')

    await screen.findByRole('heading', { name: 'No tasks match these filters' })
    await user.click(screen.getAllByRole('button', { name: 'Clear filters' })[0])

    await waitFor(() => expect(router.state.location.search).toBe('?sort=priority'))
    expect(screen.getByRole('searchbox', { name: 'Search tasks' })).toHaveValue('')
  })

  it('drops a search still waiting when the filters are cleared meanwhile', async () => {
    logIn()
    api()
    const { router, user } = renderApp('/tasks?search=xy')

    await screen.findByRole('heading', { name: 'No tasks match these filters' })
    await user.type(screen.getByRole('searchbox', { name: 'Search tasks' }), 'z')
    // The empty state's own button, within the 300 ms.
    await user.click(screen.getAllByRole('button', { name: 'Clear filters' }).at(-1)!)
    await new Promise((resolve) => setTimeout(resolve, 400))

    expect(router.state.location.search).toBe('')
    expect(screen.getByRole('searchbox', { name: 'Search tasks' })).toHaveValue('')
  })

  it('pages with links, keeping the filters', async () => {
    logIn()
    api((_init, url) => {
      const page = Number(url.searchParams.get('page') ?? 1)
      return jsonResponse(taskPage([makeTask({ title: `On page ${page}` })], { page, lastPage: 2, total: 21 }))
    })
    const { router, user } = renderApp('/tasks?priority=high')

    expect(await screen.findByText('Page 1 of 2')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Previous' })).toBeDisabled()
    await user.click(screen.getByRole('link', { name: 'Next' }))

    expect(await screen.findByText('On page 2')).toBeInTheDocument()
    expect(router.state.location.search).toBe('?priority=high&page=2')
    expect(screen.getByText('Page 2 of 2')).toBeInTheDocument()
  })

  it('goes to the last page from a page past the end', async () => {
    logIn()
    api((_init, url) => {
      const asked = Number(url.searchParams.get('page') ?? 1)
      // Like Laravel: an empty page past the end, with the real last_page.
      const page: Paginated<Task> = asked > 2 ? taskPage([], { page: asked, lastPage: 2, total: 21 }) : taskPage([makeTask({ title: `Page ${asked}` })], { page: asked, lastPage: 2, total: 21 })
      return jsonResponse(page)
    })
    const { router } = renderApp('/tasks?page=9')

    expect(await screen.findByText('Page 2')).toBeInTheDocument()
    expect(router.state.location.search).toBe('?page=2')
  })

  it('creates a task in the dialog, and shows a 422 on its field', async () => {
    logIn()
    let tasks: Task[] = []
    let attempts = 0
    const fetchMock = api(() => jsonResponse(taskPage(tasks)), {
      'POST /api/tasks': () => {
        attempts++
        if (attempts === 1) {
          return jsonResponse({ message: 'Invalid.', errors: { assignee_id: ['The assignee must be a member of this organization.'] } }, 422)
        }
        const task = makeTask({ title: 'Ship it', assignee: { id: mia.id, name: mia.name } })
        tasks = [task]
        return jsonResponse({ data: task }, 201)
      },
    })
    const { user } = renderApp('/tasks')

    await screen.findByRole('heading', { name: 'No tasks yet' })
    await user.click(screen.getAllByRole('button', { name: 'New task' })[0])
    const dialog = await screen.findByRole('dialog', { name: 'New task' })

    await user.click(within(dialog).getByRole('button', { name: 'Create task' }))
    expect(await within(dialog).findByText('Enter a title.')).toBeInTheDocument()

    await user.type(within(dialog).getByLabelText('Title'), 'Ship it')
    within(dialog).getByRole('combobox', { name: 'Assignee' }).focus()
    await user.keyboard('{Enter}')
    await user.click(await screen.findByRole('option', { name: 'Mia Member' }))
    await user.type(within(dialog).getByLabelText('Due date'), '2026-11-15')
    await user.click(within(dialog).getByRole('button', { name: 'Create task' }))
    expect(await within(dialog).findByText('The assignee must be a member of this organization.')).toBeInTheDocument()

    await user.click(within(dialog).getByRole('button', { name: 'Create task' }))
    expect(await screen.findByText('Task created')).toBeInTheDocument()
    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument())
    expect(await screen.findByRole('link', { name: 'Ship it' })).toBeInTheDocument()

    const post = fetchMock.mock.calls.find(([url, init]) => String(url).endsWith('/api/tasks') && init?.method === 'POST')
    expect(sentBody(post?.[1] ?? {})).toEqual({
      title: 'Ship it',
      description: null,
      status: 'todo',
      priority: 'medium',
      assignee_id: mia.id,
      due_date: '2026-11-15',
    })
  })

  it('lets a member create tasks too', async () => {
    logIn({ ...ada, current_organization: { ...acme, role: memberRole, permissions: ['tasks.create'] } })
    api()
    renderApp('/tasks')

    expect(await screen.findByRole('heading', { name: 'No tasks yet' })).toBeInTheDocument()
    expect(screen.getAllByRole('button', { name: 'New task' })[0]).toBeEnabled()
  })

  it('hides New task from a role without tasks.create', async () => {
    logIn({ ...ada, current_organization: { ...acme, role: { id: 9, name: 'Viewer', is_owner: false }, permissions: [] } })
    api()
    renderApp('/tasks')

    expect(await screen.findByRole('heading', { name: 'No tasks yet' })).toBeInTheDocument()
    expect(screen.getByText('Tasks created in this organization will show here.')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'New task' })).not.toBeInTheDocument()
  })

  it('drops the filters when you switch organization', async () => {
    logIn()
    api(undefined, { [`POST /api/organizations/${beta.id}/switch`]: () => jsonResponse({ data: beta }) })
    const { router, user } = renderApp(`/tasks?assignee=${mia.id}&status=done`)

    await screen.findByRole('heading', { name: 'No tasks match these filters' })
    await user.click(screen.getByRole('button', { name: 'Organization: Acme' }))
    await user.click(await screen.findByRole('menuitemcheckbox', { name: /Beta/ }))

    await waitFor(() => expect(router.state.location.pathname).toBe('/tasks'))
    expect(router.state.location.search).toBe('')
    expect(await screen.findByRole('heading', { name: 'No tasks yet' })).toBeInTheDocument()
    expect(screen.getByText('Everything to do in Beta.')).toBeInTheDocument()
  })

  it('never shows the previous organization\'s rows while the new one loads', async () => {
    logIn()
    let releaseBeta: (response: Response) => void = () => {}
    api(
      (init) =>
        (init.headers as Record<string, string>)['X-Organization-Id'] === String(acme.id)
          ? jsonResponse(taskPage([makeTask({ title: 'Acme secret' })]))
          : new Promise<Response>((resolve) => {
              releaseBeta = resolve
            }),
      { [`POST /api/organizations/${beta.id}/switch`]: () => jsonResponse({ data: beta }) },
    )
    const { user } = renderApp('/tasks')

    expect(await screen.findByRole('link', { name: 'Acme secret' })).toBeInTheDocument()
    await user.click(screen.getByRole('button', { name: 'Organization: Acme' }))
    await user.click(await screen.findByRole('menuitemcheckbox', { name: /Beta/ }))

    expect(await screen.findByLabelText('Loading tasks')).toBeInTheDocument()
    expect(screen.queryByText('Acme secret')).not.toBeInTheDocument()

    releaseBeta(jsonResponse(taskPage([makeTask({ title: 'Beta task' })])))
    expect(await screen.findByRole('link', { name: 'Beta task' })).toBeInTheDocument()
  })

  it('catches up when another tab switched organization (409)', async () => {
    logIn()
    api(
      (init) =>
        (init.headers as Record<string, string>)['X-Organization-Id'] === String(acme.id)
          ? jsonResponse({ message: 'Your current organization changed.', code: 'organization_changed' }, 409)
          : jsonResponse(taskPage([makeTask({ title: 'A Beta task' })])),
      { 'GET /api/me': () => jsonResponse({ data: { ...ada, current_organization: beta } }) },
    )
    renderApp('/tasks')

    expect(await screen.findByRole('link', { name: 'A Beta task' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Organization: Beta' })).toBeInTheDocument()
  })
})
