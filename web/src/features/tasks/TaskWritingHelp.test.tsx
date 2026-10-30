import { screen, waitFor, within } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { makeTask, meMember, mia, noSummary, taskPage } from '@/test/tasks'
import { acme, ada, beta, jsonResponse, logIn, mockApi, organizationList, renderApp, sentBody } from '@/test/utils'
import type { Task } from '@/types/api'

type Handler = (init: RequestInit, url: URL) => Response

const encoder = new TextEncoder()
const event = (name: string, data: unknown) => `event: ${name}\ndata: ${JSON.stringify(data)}\n\n`

/**
 * An event-stream response with these pieces (each its own chunk). Open: it stays
 * open after them until the request is aborted, like an answer still being written.
 */
function sseResponse(init: RequestInit, pieces: string[], { open = false } = {}): Response {
  const body = new ReadableStream<Uint8Array>({
    start(controller) {
      pieces.forEach((piece) => controller.enqueue(encoder.encode(piece)))
      if (open) {
        init.signal?.addEventListener('abort', () => controller.error(new DOMException('Aborted', 'AbortError')))
      } else {
        controller.close()
      }
    },
  })
  return new Response(body, { status: 200, headers: { 'Content-Type': 'text/event-stream' } })
}

const answer = (init: RequestInit) =>
  sseResponse(init, [
    event('delta', { text: 'A clearer ' }),
    event('delta', { text: 'description.' }),
    event('done', { model: 'claude-haiku-5-5' }),
  ])

function api(overrides: Record<string, Handler> = {}) {
  return mockApi({
    'GET /api/organizations': () => jsonResponse(organizationList([acme, beta])),
    'GET /api/members': () => jsonResponse({ data: [meMember, mia] }),
    'GET /api/tasks': () => jsonResponse(taskPage([])),
    'POST /api/tasks/writing-help': answer,
    ...overrides,
  })
}

async function openNewTask(user: ReturnType<typeof renderApp>['user']) {
  await screen.findByRole('heading', { name: 'No tasks yet' })
  await user.click(screen.getAllByRole('button', { name: 'New task' })[0])
  return screen.findByRole('dialog', { name: 'New task' })
}

async function pick(user: ReturnType<typeof renderApp>['user'], dialog: HTMLElement, mode: string) {
  await user.click(within(dialog).getByRole('button', { name: 'Improve with AI' }))
  await user.click(await screen.findByRole('menuitem', { name: mode }))
}

describe('TaskWritingHelp', () => {
  it('streams a suggestion that Accept puts into the description, which the form then saves', async () => {
    logIn()
    const fetchMock = api({
      'POST /api/tasks': (init) => jsonResponse({ data: makeTask({ ...(sentBody(init) as Partial<Task>), id: 7 }) }, 201),
    })
    const { user } = renderApp('/tasks')
    const dialog = await openNewTask(user)
    await user.type(within(dialog).getByLabelText('Title'), 'Launch')
    await user.type(within(dialog).getByLabelText('Description'), 'we launch stuff')

    await pick(user, dialog, 'Improve')

    const suggestion = await within(dialog).findByRole('region', { name: 'AI suggestion' })
    expect(await within(suggestion).findByText('A clearer description.')).toBeInTheDocument()
    const request = fetchMock.mock.calls.find(([url]) => String(url).endsWith('/api/tasks/writing-help'))
    expect(sentBody(request?.[1] ?? {})).toEqual({ mode: 'improve', title: 'Launch', description: 'we launch stuff' })
    expect(new Headers(request?.[1]?.headers).get('Accept')).toBe('text/event-stream')

    await user.click(within(suggestion).getByRole('button', { name: 'Accept' }))
    expect(within(dialog).getByLabelText('Description')).toHaveValue('A clearer description.')
    expect(within(dialog).getByLabelText('Description')).toHaveFocus()
    expect(within(dialog).queryByRole('region', { name: 'AI suggestion' })).not.toBeInTheDocument()

    await user.click(within(dialog).getByRole('button', { name: 'Create task' }))
    await waitFor(() => expect(fetchMock.mock.calls.some(([url, init]) => String(url).endsWith('/api/tasks') && init?.method === 'POST')).toBe(true))
    const create = fetchMock.mock.calls.find(([url, init]) => String(url).endsWith('/api/tasks') && init?.method === 'POST')
    expect(sentBody(create?.[1] ?? {})).toMatchObject({ title: 'Launch', description: 'A clearer description.' })
  })

  it('leaves the description as it was on Discard', async () => {
    logIn()
    api()
    const { user } = renderApp('/tasks')
    const dialog = await openNewTask(user)
    await user.type(within(dialog).getByLabelText('Description'), 'my words')

    await pick(user, dialog, 'Shorten')
    const suggestion = await within(dialog).findByRole('region', { name: 'AI suggestion' })
    await within(suggestion).findByText('A clearer description.')
    await user.click(within(suggestion).getByRole('button', { name: 'Discard' }))

    expect(within(dialog).getByLabelText('Description')).toHaveValue('my words')
    expect(within(dialog).queryByRole('region', { name: 'AI suggestion' })).not.toBeInTheDocument()
    expect(within(dialog).getByRole('button', { name: 'Improve with AI' })).toHaveFocus()
  })

  it('needs text to work on: Expand from a title alone, Improve and Shorten only with a description', async () => {
    logIn()
    api()
    const { user } = renderApp('/tasks')
    const dialog = await openNewTask(user)

    expect(within(dialog).getByRole('button', { name: 'Improve with AI' })).toBeDisabled()

    await user.type(within(dialog).getByLabelText('Title'), 'Launch')
    await user.click(within(dialog).getByRole('button', { name: 'Improve with AI' }))
    expect(await screen.findByRole('menuitem', { name: 'Improve' })).toHaveAttribute('aria-disabled', 'true')
    expect(screen.getByRole('menuitem', { name: 'Shorten' })).toHaveAttribute('aria-disabled', 'true')
    expect(screen.getByRole('menuitem', { name: 'Expand' })).not.toHaveAttribute('aria-disabled')
  })

  it('stops a suggestion while it is written, offering only Try again and Discard', async () => {
    logIn()
    api({ 'POST /api/tasks/writing-help': (init) => sseResponse(init, [event('delta', { text: 'Half an ' })], { open: true }) })
    const { user } = renderApp('/tasks')
    const dialog = await openNewTask(user)
    await user.type(within(dialog).getByLabelText('Description'), 'my words')

    await pick(user, dialog, 'Improve')
    const suggestion = await within(dialog).findByRole('region', { name: 'AI suggestion' })
    expect(await within(suggestion).findByText('Half an')).toBeInTheDocument()
    expect(within(suggestion).getByText('Writing…')).toBeInTheDocument()

    await user.click(within(suggestion).getByRole('button', { name: 'Stop' }))

    expect(await within(suggestion).findByText('Stopped.')).toBeInTheDocument()
    expect(within(suggestion).queryByRole('button', { name: 'Accept' })).not.toBeInTheDocument()
    expect(within(suggestion).getByRole('button', { name: 'Try again' })).toBeInTheDocument()
    expect(within(suggestion).getByRole('button', { name: 'Discard' })).toBeInTheDocument()
  })

  it('shows the message of an error part-way, without Accept', async () => {
    logIn()
    api({
      'POST /api/tasks/writing-help': (init) =>
        sseResponse(init, [event('delta', { text: 'Partial ' }), event('error', { message: 'The AI declined this request.' })]),
    })
    const { user } = renderApp('/tasks')
    const dialog = await openNewTask(user)
    await user.type(within(dialog).getByLabelText('Description'), 'my words')

    await pick(user, dialog, 'Improve')

    const suggestion = await within(dialog).findByRole('region', { name: 'AI suggestion' })
    expect(await within(suggestion).findByText('The AI declined this request.')).toBeInTheDocument()
    expect(within(suggestion).queryByRole('button', { name: 'Accept' })).not.toBeInTheDocument()
  })

  it('keeps the text and says it broke off when the connection drops part-way', async () => {
    logIn()
    api({
      'POST /api/tasks/writing-help': () => {
        let sent = false
        const body = new ReadableStream<Uint8Array>({
          pull(controller) {
            if (!sent) {
              sent = true
              controller.enqueue(encoder.encode(event('delta', { text: 'Half an ' })))
            } else {
              controller.error(new TypeError('network error'))
            }
          },
        })
        return new Response(body, { status: 200, headers: { 'Content-Type': 'text/event-stream' } })
      },
    })
    const { user } = renderApp('/tasks')
    const dialog = await openNewTask(user)
    await user.type(within(dialog).getByLabelText('Description'), 'my words')

    await pick(user, dialog, 'Improve')

    const suggestion = await within(dialog).findByRole('region', { name: 'AI suggestion' })
    expect(await within(suggestion).findByText('The answer broke off. Try again.')).toBeInTheDocument()
    expect(within(suggestion).getByText('Half an')).toBeInTheDocument()
    expect(within(suggestion).queryByRole('button', { name: 'Accept' })).not.toBeInTheDocument()
  })

  it('disables Try again when the text it needs was cleared', async () => {
    logIn()
    api()
    const { user } = renderApp('/tasks')
    const dialog = await openNewTask(user)
    await user.type(within(dialog).getByLabelText('Title'), 'Launch')
    await user.type(within(dialog).getByLabelText('Description'), 'my words')

    await pick(user, dialog, 'Improve')
    const suggestion = await within(dialog).findByRole('region', { name: 'AI suggestion' })
    await within(suggestion).findByText('A clearer description.')
    await user.clear(within(dialog).getByLabelText('Description'))

    expect(within(suggestion).getByRole('button', { name: 'Try again' })).toBeDisabled()
  })

  it('says why when the request is refused before any text (budget, too many at once)', async () => {
    logIn()
    let status = 402
    api({
      'POST /api/tasks/writing-help': () =>
        status === 402
          ? jsonResponse({ message: "This organization's monthly AI budget is used up.", code: 'ai_budget_exceeded' }, 402)
          : jsonResponse({ message: 'Too many AI answers are being written for this organization. Try again when one finishes.' }, 429),
    })
    const { user } = renderApp('/tasks')
    const dialog = await openNewTask(user)
    await user.type(within(dialog).getByLabelText('Description'), 'my words')

    await pick(user, dialog, 'Improve')
    expect(await screen.findByText("This organization's monthly AI budget is used up.")).toBeInTheDocument()
    expect(within(dialog).queryByRole('region', { name: 'AI suggestion' })).not.toBeInTheDocument()

    status = 429
    await pick(user, dialog, 'Improve')
    expect(
      await screen.findByText('Too many AI answers are being written for this organization. Try again when one finishes.'),
    ).toBeInTheDocument()
  })

  it('is offered only with ai.use and a verified email in the New task form', async () => {
    logIn({ ...ada, current_organization: { ...acme, permissions: ['tasks.create'] } })
    api()
    const { user } = renderApp('/tasks')
    const dialog = await openNewTask(user)
    expect(within(dialog).queryByRole('button', { name: 'Improve with AI' })).not.toBeInTheDocument()
  })

  it('is hidden in the New task form with an unverified email', async () => {
    logIn({ ...ada, email_verified: false })
    api()
    const { user } = renderApp('/tasks')
    const dialog = await openNewTask(user)
    expect(within(dialog).queryByRole('button', { name: 'Improve with AI' })).not.toBeInTheDocument()
  })

  it('uses the task\'s own endpoint when editing', async () => {
    logIn()
    const task = makeTask({ id: 42, title: 'Write docs', description: 'Old words' })
    const fetchMock = api({
      'GET /api/tasks/42': () => jsonResponse({ data: task }),
      'GET /api/tasks/42/summary': () => jsonResponse(noSummary),
      'POST /api/tasks/42/writing-help': answer,
    })
    const { user } = renderApp('/tasks/42')

    await user.click(await screen.findByRole('button', { name: 'Edit' }))
    const dialog = await screen.findByRole('dialog', { name: 'Edit task' })
    await pick(user, dialog, 'Improve')
    await within(dialog).findByText('A clearer description.')
    const request = fetchMock.mock.calls.find(([url]) => String(url).endsWith('/api/tasks/42/writing-help'))
    expect(sentBody(request?.[1] ?? {})).toEqual({ mode: 'improve', title: 'Write docs', description: 'Old words' })
  })

  it('is hidden when editing a task whose can.write_with_ai is false', async () => {
    logIn()
    const task = makeTask({ id: 42, description: 'Old words' })
    api({
      'GET /api/tasks/42': () => jsonResponse({ data: { ...task, can: { ...task.can, write_with_ai: false } } }),
      'GET /api/tasks/42/summary': () => jsonResponse(noSummary),
    })
    const { user } = renderApp('/tasks/42')

    await user.click(await screen.findByRole('button', { name: 'Edit' }))
    const dialog = await screen.findByRole('dialog', { name: 'Edit task' })
    expect(within(dialog).queryByRole('button', { name: 'Improve with AI' })).not.toBeInTheDocument()
  })
})
