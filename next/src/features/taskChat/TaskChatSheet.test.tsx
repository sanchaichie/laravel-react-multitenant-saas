import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it } from 'vitest'
import type { TaskChatMessage } from '@shared/types/api'
import { makeTask } from '@/test/fixtures'
import { json, manualStream, mockFetch, sseEvent, sseResponse } from '@/test/next'
import { renderWithClient } from '@/test/render'
import { TaskChatSheet } from './TaskChatSheet'

const task = makeTask({ id: 42, title: 'Write the report' })

function message(id: number, role: TaskChatMessage['role'], content: string, status: TaskChatMessage['status'] = 'complete'): TaskChatMessage {
  return { id, role, content, status, created_at: '2026-11-01T12:00:00.000000Z' }
}

async function openChat(verified = true) {
  const user = userEvent.setup()
  renderWithClient(<TaskChatSheet task={task} organizationId={10} verified={verified} />)
  await user.click(screen.getByRole('button', { name: 'Ask AI' }))
  return user
}

describe('the task chat', () => {
  it('shows the saved conversation, oldest first', async () => {
    mockFetch({
      'GET /api/tasks/42/chat': () => json({ data: [message(1, 'user', "What's left?"), message(2, 'assistant', 'The summary section.')] }),
    })

    await openChat()

    const log = await screen.findByRole('log', { name: 'Conversation' })
    const items = within(log).getAllByRole('listitem')
    expect(items[0]).toHaveTextContent("You:What's left?")
    expect(items[1]).toHaveTextContent('AI:The summary section.')
  })

  it('streams an answer in, then shows the saved rows', async () => {
    let history: TaskChatMessage[] = []
    const stream = manualStream()
    const calls = mockFetch({
      'GET /api/tasks/42/chat': () => json({ data: history }),
      'POST /api/tasks/42/chat': (init) => stream.respond(init),
    })
    const user = await openChat()
    await screen.findByText('No messages yet')

    await user.type(screen.getByRole('textbox', { name: 'Your question' }), 'Any risks?{Enter}')

    await screen.findByText('Writing…')
    stream.handle.send(sseEvent('delta', { text: 'The deadline ' }))
    expect(await screen.findByText('The deadline')).toBeInTheDocument()
    history = [message(1, 'user', 'Any risks?'), message(2, 'assistant', 'The deadline is tight.')]
    stream.handle.send(sseEvent('delta', { text: 'is tight.' }))
    stream.handle.send(sseEvent('done', { model: 'fake' }))
    stream.handle.close()

    await waitFor(() => expect(screen.queryByText('Writing…')).not.toBeInTheDocument())
    expect(screen.getByText('The deadline is tight.')).toBeInTheDocument()
    const post = calls.find((call) => call.method === 'POST')!
    expect(JSON.parse(post.init.body as string)).toEqual({ message: 'Any risks?' })
    expect(new Headers(post.init.headers).get('x-organization-id')).toBe('10')
    // The composer is empty again.
    expect(screen.getByRole('textbox', { name: 'Your question' })).toHaveValue('')
  })

  it('Shift+Enter adds a line instead of sending', async () => {
    const calls = mockFetch({ 'GET /api/tasks/42/chat': () => json({ data: [] }) })
    const user = await openChat()
    await screen.findByText('No messages yet')

    await user.type(screen.getByRole('textbox', { name: 'Your question' }), 'One{Shift>}{Enter}{/Shift}Two')

    expect(screen.getByRole('textbox', { name: 'Your question' })).toHaveValue('One\nTwo')
    expect(calls.filter((call) => call.method === 'POST')).toHaveLength(0)
  })

  it('Stop keeps the partial answer, marked stopped, and aborts the request', async () => {
    const stream = manualStream()
    mockFetch({
      'GET /api/tasks/42/chat': () => json({ data: [] }),
      'POST /api/tasks/42/chat': (init) => stream.respond(init),
    })
    const user = await openChat()
    await screen.findByText('No messages yet')
    await user.type(screen.getByRole('textbox', { name: 'Your question' }), 'Plan?{Enter}')
    stream.handle.send(sseEvent('delta', { text: 'First, ' }))
    await screen.findByText('First,')

    await user.click(screen.getByRole('button', { name: 'Stop' }))

    expect(await screen.findByText('Stopped')).toBeInTheDocument()
    expect(stream.handle.aborted).toBe(true)
    expect(screen.getByRole('button', { name: 'Send' })).toBeInTheDocument()
  })

  it('clears the conversation after confirming', async () => {
    const calls = mockFetch({
      'GET /api/tasks/42/chat': () => json({ data: [message(1, 'user', 'Hi'), message(2, 'assistant', 'Hello.')] }),
      'DELETE /api/tasks/42/chat': () => new Response(null, { status: 204 }),
    })
    const user = await openChat()
    await screen.findByText('Hello.')

    await user.click(screen.getByRole('button', { name: 'Clear conversation' }))
    await user.click(await screen.findByRole('button', { name: 'Clear' }))

    expect(await screen.findByText('No messages yet')).toBeInTheDocument()
    expect(calls.some((call) => call.method === 'DELETE')).toBe(true)
  })

  it('gives the question back when the budget is used up', async () => {
    mockFetch({
      'GET /api/tasks/42/chat': () => json({ data: [] }),
      'POST /api/tasks/42/chat': () => json({ message: "This organization's AI budget is used up.", code: 'ai_budget_exceeded' }, 402),
    })
    const user = await openChat()
    await screen.findByText('No messages yet')

    await user.type(screen.getByRole('textbox', { name: 'Your question' }), 'Any risks?{Enter}')

    expect(await screen.findByText('Could not send your question')).toBeInTheDocument()
    expect(screen.getByRole('textbox', { name: 'Your question' })).toHaveValue('Any risks?')
  })

  it('reports an answer that failed', async () => {
    mockFetch({
      'GET /api/tasks/42/chat': () => json({ data: [message(1, 'user', 'Hi'), message(2, 'assistant', '', 'failed')] }),
      'POST /api/tasks/42/chat': () => sseResponse([], { error: 'The AI request failed.' }),
    })

    await openChat()

    expect(await screen.findByText("Couldn't answer")).toBeInTheDocument()
  })

  it('shows an unverified user their history, without the composer', async () => {
    mockFetch({ 'GET /api/tasks/42/chat': () => json({ data: [message(1, 'user', 'Earlier question')] }) })

    await openChat(false)

    expect(await screen.findByText('Earlier question')).toBeInTheDocument()
    expect(screen.getByText('Verify your email to chat.')).toBeInTheDocument()
    expect(screen.queryByRole('textbox', { name: 'Your question' })).not.toBeInTheDocument()
  })
})

describe('the cached history', () => {
  it('is dropped once the chat closes, so the next opening never shows it', async () => {
    mockFetch({ 'GET /api/tasks/42/chat': () => json({ data: [message(1, 'user', 'Private question')] }) })
    const user = userEvent.setup()
    const { queryClient } = renderWithClient(<TaskChatSheet task={task} organizationId={10} verified />)
    await user.click(screen.getByRole('button', { name: 'Ask AI' }))
    await screen.findByText('Private question')

    await user.keyboard('{Escape}')

    await waitFor(() => expect(queryClient.getQueryCache().findAll({ queryKey: ['org', 10, 'tasks', 'detail', 42, 'chat'] })).toHaveLength(0))
  })
})

describe('one exchange at a time', () => {
  it('keeps a stopped answer on screen, then shows each message once after the next question', async () => {
    const history: TaskChatMessage[] = []
    let posts = 0
    const first = manualStream()
    const calls = mockFetch({
      'GET /api/tasks/42/chat': () => json({ data: [...history] }),
      'POST /api/tasks/42/chat': (init) => {
        const question = (JSON.parse(init.body as string) as { message: string }).message
        if (++posts === 1) {
          // The server saves the partial answer once it notices the stop.
          history.push(message(1, 'user', question), message(2, 'assistant', 'Half an ', 'stopped'))
          return first.respond(init)
        }
        history.push(message(3, 'user', question), message(4, 'assistant', 'Full answer.'))
        return sseResponse(['Full answer.'])
      },
    })
    const user = await openChat()
    const log = await screen.findByRole('log', { name: 'Conversation' })

    await user.type(screen.getByRole('textbox', { name: 'Your question' }), 'First?{Enter}')
    first.handle.send(sseEvent('delta', { text: 'Half an ' }))
    await within(log).findByText('Half an')
    await user.click(screen.getByRole('button', { name: 'Stop' }))
    await within(log).findByText('Stopped')

    await user.type(screen.getByRole('textbox', { name: 'Your question' }), 'Second?{Enter}')

    expect(await within(log).findByText('Full answer.')).toBeInTheDocument()
    await waitFor(() =>
      expect(within(log).getAllByRole('listitem').map((item) => item.textContent)).toEqual([
        'You:First?',
        'AI:Half an Stopped',
        'You:Second?',
        'AI:Full answer.',
      ]),
    )
    expect(calls.filter((call) => call.method === 'POST')).toHaveLength(2)
  })

  it('sends a question once, however fast Enter is pressed', async () => {
    const stream = manualStream()
    const calls = mockFetch({
      'GET /api/tasks/42/chat': () => json({ data: [] }),
      'POST /api/tasks/42/chat': (init) => stream.respond(init),
    })
    const user = await openChat()
    await screen.findByText('No messages yet')

    await user.type(screen.getByRole('textbox', { name: 'Your question' }), 'Plan?')
    await user.keyboard('{Enter}{Enter}')
    await user.click(await screen.findByRole('button', { name: 'Stop' }))

    expect(calls.filter((call) => call.method === 'POST')).toHaveLength(1)
  })

  it('the Ask AI button is a dialog trigger', () => {
    mockFetch({})
    renderWithClient(<TaskChatSheet task={task} organizationId={10} verified />)

    expect(screen.getByRole('button', { name: 'Ask AI' })).toHaveAttribute('aria-haspopup', 'dialog')
  })
})
