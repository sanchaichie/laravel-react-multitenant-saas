import { screen, waitFor, within } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { makeTask, meMember, mia, noSummary } from '@/test/tasks'
import { acme, ada, beta, jsonResponse, logIn, mockApi, organizationList, renderApp, sentBody } from '@/test/utils'
import type { TaskChatMessage } from '@/types/api'

type Handler = (init: RequestInit, url: URL) => Response

const encoder = new TextEncoder()
const event = (name: string, data: unknown) => `event: ${name}\ndata: ${JSON.stringify(data)}\n\n`

/** An event-stream response; open: it stays open (an answer still being written) until aborted. */
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

const task = makeTask({ id: 42, title: 'Relaunch the site', description: 'Move the blog' })
let nextId = 1
const message = (role: TaskChatMessage['role'], content: string, status: TaskChatMessage['status'] = 'complete'): TaskChatMessage => ({
  id: nextId++,
  role,
  content,
  status,
  created_at: '2026-10-31T10:00:00.000000Z',
})

/**
 * The API with a chat kept on the "server": POST /chat saves the question and the
 * answer it streams, like SendTaskChatMessage.
 */
function api(overrides: Record<string, Handler> = {}, saved: TaskChatMessage[] = []) {
  const chat = [...saved]
  const fetchMock = mockApi({
    'GET /api/organizations': () => jsonResponse(organizationList([acme, beta])),
    'GET /api/members': () => jsonResponse({ data: [meMember, mia] }),
    'GET /api/tasks/42': () => jsonResponse({ data: task }),
    'GET /api/tasks/42/summary': () => jsonResponse(noSummary),
    'GET /api/tasks/42/chat': () => jsonResponse({ data: chat }),
    'POST /api/tasks/42/chat': (init) => {
      const question = (sentBody(init) as { message: string }).message
      chat.push(message('user', question), message('assistant', 'It is due in March.'))
      return sseResponse(init, [event('delta', { text: 'It is due ' }), event('delta', { text: 'in March.' }), event('done', { model: 'm' })])
    },
    'DELETE /api/tasks/42/chat': () => {
      chat.length = 0
      return new Response(null, { status: 204 })
    },
    ...overrides,
  })
  return { fetchMock, chat }
}

async function openChat(user: ReturnType<typeof renderApp>['user']) {
  await user.click(await screen.findByRole('button', { name: 'Ask AI' }))
  return screen.findByRole('dialog', { name: 'Ask AI about this task' })
}

describe('TaskChatSheet', () => {
  it('shows the empty state, then streams an answer under the question and keeps both', async () => {
    logIn()
    const { fetchMock } = api()
    const { user } = renderApp('/tasks/42')
    const sheet = await openChat(user)

    expect(await within(sheet).findByText('No messages yet')).toBeInTheDocument()

    await user.type(within(sheet).getByLabelText('Your question'), 'When is it due?{Enter}')

    const log = await within(sheet).findByRole('log', { name: 'Conversation' })
    expect(within(log).getByText('When is it due?')).toBeInTheDocument()
    expect(await within(log).findByText('It is due in March.')).toBeInTheDocument()
    expect(within(sheet).getByLabelText('Your question')).toHaveValue('')
    const post = fetchMock.mock.calls.find(([, init]) => init?.method === 'POST')
    expect(sentBody(post?.[1] ?? {})).toEqual({ message: 'When is it due?' })

    // The saved history replaces the live copy: no duplicates.
    await waitFor(() => expect(within(log).getAllByRole('listitem')).toHaveLength(2))
  })

  it('loads the saved conversation, oldest first', async () => {
    logIn()
    api({}, [message('user', 'First question'), message('assistant', 'First answer'), message('assistant', 'Half', 'stopped')])
    const { user } = renderApp('/tasks/42')
    const sheet = await openChat(user)

    const log = await within(sheet).findByRole('log', { name: 'Conversation' })
    const items = within(log).getAllByRole('listitem')
    expect(items.map((item) => item.textContent)).toEqual(['You:First question', 'AI:First answer', 'AI:HalfStopped'])
  })

  it('adds a line with Shift+Enter instead of sending', async () => {
    logIn()
    const { fetchMock } = api()
    const { user } = renderApp('/tasks/42')
    const sheet = await openChat(user)

    await user.type(within(sheet).getByLabelText('Your question'), 'Line one{Shift>}{Enter}{/Shift}Line two')

    expect(within(sheet).getByLabelText('Your question')).toHaveValue('Line one\nLine two')
    expect(fetchMock.mock.calls.some(([, init]) => init?.method === 'POST')).toBe(false)
  })

  it('stops an answer being written, keeping what arrived', async () => {
    logIn()
    api({ 'POST /api/tasks/42/chat': (init) => sseResponse(init, [event('delta', { text: 'Half an ' })], { open: true }) })
    const { user } = renderApp('/tasks/42')
    const sheet = await openChat(user)

    await user.type(within(sheet).getByLabelText('Your question'), 'Plan?{Enter}')
    const log = await within(sheet).findByRole('log')
    expect(await within(log).findByText('Half an')).toBeInTheDocument()
    expect(within(log).getByText('Writing…')).toBeInTheDocument()

    await user.click(within(sheet).getByRole('button', { name: 'Stop' }))

    expect(await within(log).findByText('Stopped')).toBeInTheDocument()
    expect(within(log).getByText('Half an')).toBeInTheDocument()
    expect(within(sheet).getByRole('button', { name: 'Send' })).toBeInTheDocument()
  })

  it('keeps a stopped answer on screen, then shows each message once after the next question', async () => {
    logIn()
    let calls = 0
    const { fetchMock, chat } = api({
      'POST /api/tasks/42/chat': (init) => {
        const question = (sentBody(init) as { message: string }).message
        if (++calls === 1) {
          // The server saves the partial answer once it notices the stop.
          chat.push(message('user', question), message('assistant', 'Half an ', 'stopped'))
          return sseResponse(init, [event('delta', { text: 'Half an ' })], { open: true })
        }
        chat.push(message('user', question), message('assistant', 'Full answer.'))
        return sseResponse(init, [event('delta', { text: 'Full answer.' }), event('done', { model: 'm' })])
      },
    })
    const { user } = renderApp('/tasks/42')
    const sheet = await openChat(user)

    await user.type(within(sheet).getByLabelText('Your question'), 'First?{Enter}')
    const log = await within(sheet).findByRole('log')
    await within(log).findByText('Half an')
    await user.click(within(sheet).getByRole('button', { name: 'Stop' }))
    await within(log).findByText('Stopped')

    await user.type(within(sheet).getByLabelText('Your question'), 'Second?{Enter}')

    expect(await within(log).findByText('Full answer.')).toBeInTheDocument()
    await waitFor(() =>
      expect(within(log).getAllByRole('listitem').map((item) => item.textContent)).toEqual([
        'You:First?',
        'AI:Half an Stopped',
        'You:Second?',
        'AI:Full answer.',
      ]),
    )
    expect(fetchMock.mock.calls.filter(([, init]) => init?.method === 'POST')).toHaveLength(2)
  })

  it('sends a question once, however fast Enter is pressed', async () => {
    logIn()
    const { fetchMock } = api({ 'POST /api/tasks/42/chat': (init) => sseResponse(init, [event('delta', { text: 'Thinking' })], { open: true }) })
    const { user } = renderApp('/tasks/42')
    const sheet = await openChat(user)

    await user.type(within(sheet).getByLabelText('Your question'), 'Plan?')
    await user.keyboard('{Enter}{Enter}')
    await user.click(within(sheet).getByRole('button', { name: 'Stop' }))

    expect(fetchMock.mock.calls.filter(([, init]) => init?.method === 'POST')).toHaveLength(1)
  })

  it('clears a stopped exchange along with the saved ones', async () => {
    logIn()
    const { chat } = api({
      'POST /api/tasks/42/chat': (init) => {
        chat.push(message('user', 'Plan?'), message('assistant', 'Half', 'stopped'))
        return sseResponse(init, [event('delta', { text: 'Half' })], { open: true })
      },
    })
    const { user } = renderApp('/tasks/42')
    const sheet = await openChat(user)

    await user.type(within(sheet).getByLabelText('Your question'), 'Plan?{Enter}')
    await within(sheet).findByText('Half')
    await user.click(within(sheet).getByRole('button', { name: 'Stop' }))

    await user.click(await within(sheet).findByRole('button', { name: 'Clear conversation' }))
    await user.click(within(await screen.findByRole('alertdialog')).getByRole('button', { name: 'Clear' }))

    expect(await within(sheet).findByText('No messages yet')).toBeInTheDocument()
  })

  it('says when the AI couldn\'t answer', async () => {
    logIn()
    const { chat } = api({
      'POST /api/tasks/42/chat': (init) => {
        chat.push(message('user', 'Plan?'), message('assistant', 'Partial ', 'failed'))
        return sseResponse(init, [event('delta', { text: 'Partial ' }), event('error', { message: 'The AI declined this request.' })])
      },
    })
    const { user } = renderApp('/tasks/42')
    const sheet = await openChat(user)

    await user.type(within(sheet).getByLabelText('Your question'), 'Plan?{Enter}')

    expect(await screen.findByText('The AI declined this request.')).toBeInTheDocument()
    const log = within(sheet).getByRole('log')
    expect(await within(log).findByText("Couldn't answer")).toBeInTheDocument()
  })

  it('gives the question back when the request is refused (budget, too many)', async () => {
    logIn()
    let status = 402
    api({
      'POST /api/tasks/42/chat': () =>
        status === 402
          ? jsonResponse({ message: "This organization's monthly AI budget is used up.", code: 'ai_budget_exceeded' }, 402)
          : jsonResponse({ message: 'You already have AI answers being written. Try again when one finishes.' }, 429),
    })
    const { user } = renderApp('/tasks/42')
    const sheet = await openChat(user)

    await user.type(within(sheet).getByLabelText('Your question'), 'Plan?{Enter}')
    expect(await screen.findByText("This organization's monthly AI budget is used up.")).toBeInTheDocument()
    expect(within(sheet).getByLabelText('Your question')).toHaveValue('Plan?')
    expect(within(sheet).getByText('No messages yet')).toBeInTheDocument()

    status = 429
    await user.click(within(sheet).getByRole('button', { name: 'Send' }))
    expect(await screen.findByText('You already have AI answers being written. Try again when one finishes.')).toBeInTheDocument()
  })

  it('clears the conversation after confirming', async () => {
    logIn()
    const { fetchMock } = api({}, [message('user', 'Old question'), message('assistant', 'Old answer')])
    const { user } = renderApp('/tasks/42')
    const sheet = await openChat(user)
    await within(sheet).findByText('Old answer')

    await user.click(within(sheet).getByRole('button', { name: 'Clear conversation' }))
    const confirm = await screen.findByRole('alertdialog', { name: 'Clear this conversation?' })
    await user.click(within(confirm).getByRole('button', { name: 'Clear' }))

    expect(await within(sheet).findByText('No messages yet')).toBeInTheDocument()
    expect(fetchMock.mock.calls.some(([, init]) => init?.method === 'DELETE')).toBe(true)
  })

  it('shows an error with Retry when the conversation can\'t load', async () => {
    logIn()
    let fail = true
    api({ 'GET /api/tasks/42/chat': () => (fail ? jsonResponse({ message: 'Server error' }, 500) : jsonResponse({ data: [] })) })
    const { user } = renderApp('/tasks/42')
    const sheet = await openChat(user)

    expect(await within(sheet).findByText(/Could not load the conversation/, {}, { timeout: 3000 })).toBeInTheDocument()
    fail = false
    await user.click(within(sheet).getByRole('button', { name: 'Retry' }))
    expect(await within(sheet).findByText('No messages yet')).toBeInTheDocument()
  })

  it('has no Ask AI button without can.chat', async () => {
    logIn()
    api({ 'GET /api/tasks/42': () => jsonResponse({ data: { ...task, can: { ...task.can, chat: false } } }) })
    renderApp('/tasks/42')

    expect(await screen.findByRole('heading', { name: 'Relaunch the site' })).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Ask AI' })).not.toBeInTheDocument()
  })

  it('shows the history but asks an unverified user to verify before asking', async () => {
    logIn({ ...ada, email_verified: false })
    api({}, [message('user', 'Earlier question')])
    const { user } = renderApp('/tasks/42')
    const sheet = await openChat(user)

    expect(await within(sheet).findByText('Earlier question')).toBeInTheDocument()
    expect(within(sheet).getByText('Verify your email address to ask questions.')).toBeInTheDocument()
    expect(within(sheet).queryByLabelText('Your question')).not.toBeInTheDocument()
    expect(within(sheet).getByRole('button', { name: 'Clear conversation' })).toBeInTheDocument()
  })
})
