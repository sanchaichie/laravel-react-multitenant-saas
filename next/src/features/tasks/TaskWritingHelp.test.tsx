import { screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it, vi } from 'vitest'
import { makeTask, meMember } from '@/test/fixtures'
import { json, mockFetch, sseResponse } from '@/test/next'
import { renderWithClient } from '@/test/render'
import { TaskForm } from './TaskForm'

vi.mock('next/navigation', () => ({ useRouter: () => ({ replace: vi.fn(), refresh: vi.fn() }) }))
// The form's Server Actions aren't called here: writing help never saves.
vi.mock('./actions', () => ({ createTask: vi.fn(), updateTask: vi.fn() }))

function renderForm(props: { writingHelp: boolean; task?: ReturnType<typeof makeTask> }) {
  return renderWithClient(<TaskForm organizationId={10} members={[meMember]} {...props} />)
}

async function chooseMode(user: ReturnType<typeof userEvent.setup>, label: string) {
  await user.click(screen.getByRole('button', { name: 'Improve with AI' }))
  await user.click(await screen.findByRole('menuitem', { name: label }))
}

describe('writing help in the task form', () => {
  it('streams a suggestion, and Accept puts it in the description without saving', async () => {
    const calls = mockFetch({ 'POST /api/tasks/42/writing-help': () => sseResponse(['A clearer ', 'description.']) })
    const user = userEvent.setup()
    renderForm({ writingHelp: true, task: makeTask({ id: 42, title: 'Report', description: 'make report' }) })

    await chooseMode(user, 'Improve')

    expect(await screen.findByText('A clearer description.')).toBeInTheDocument()
    expect(JSON.parse(calls[0].init.body as string)).toEqual({ mode: 'improve', title: 'Report', description: 'make report' })
    expect(new Headers(calls[0].init.headers).get('x-organization-id')).toBe('10')

    await user.click(screen.getByRole('button', { name: 'Accept' }))

    const description = screen.getByRole('textbox', { name: 'Description' })
    expect(description).toHaveValue('A clearer description.')
    expect(description).toHaveFocus()
    expect(screen.queryByRole('region', { name: 'AI suggestion' })).not.toBeInTheDocument()
    expect(calls).toHaveLength(1)
  })

  it('sends what is typed now, not the saved task, and uses the new-task route', async () => {
    const calls = mockFetch({ 'POST /api/tasks/writing-help': () => sseResponse(['Expanded.']) })
    const user = userEvent.setup()
    renderForm({ writingHelp: true })

    await user.type(screen.getByRole('textbox', { name: 'Title' }), 'Plan the offsite')
    await chooseMode(user, 'Expand')

    await screen.findByText('Expanded.')
    expect(JSON.parse(calls[0].init.body as string)).toEqual({ mode: 'expand', title: 'Plan the offsite', description: null })
  })

  it('offers Improve and Shorten only with a description', async () => {
    mockFetch({})
    const user = userEvent.setup()
    renderForm({ writingHelp: true })
    await user.type(screen.getByRole('textbox', { name: 'Title' }), 'Only a title')

    await user.click(screen.getByRole('button', { name: 'Improve with AI' }))

    expect(await screen.findByRole('menuitem', { name: 'Improve' })).toHaveAttribute('aria-disabled', 'true')
    expect(screen.getByRole('menuitem', { name: 'Shorten' })).toHaveAttribute('aria-disabled', 'true')
    expect(screen.getByRole('menuitem', { name: 'Expand' })).not.toHaveAttribute('aria-disabled', 'true')
  })

  it("Discard drops the suggestion and leaves the field as it was", async () => {
    mockFetch({ 'POST /api/tasks/42/writing-help': () => sseResponse(['Shorter.']) })
    const user = userEvent.setup()
    renderForm({ writingHelp: true, task: makeTask({ id: 42, description: 'A long description' }) })

    await chooseMode(user, 'Shorten')
    await screen.findByText('Shorter.')
    await user.click(screen.getByRole('button', { name: 'Discard' }))

    await waitFor(() => expect(screen.queryByText('Shorter.')).not.toBeInTheDocument())
    expect(screen.getByRole('textbox', { name: 'Description' })).toHaveValue('A long description')
  })

  it("says why when the API refuses (a 429 with the API's own message)", async () => {
    mockFetch({ 'POST /api/tasks/42/writing-help': () => json({ message: 'Two answers are already being written. Wait for one to finish.' }, 429) })
    const user = userEvent.setup()
    renderForm({ writingHelp: true, task: makeTask({ id: 42, description: 'Text' }) })

    await chooseMode(user, 'Improve')

    expect(await screen.findByText('Two answers are already being written. Wait for one to finish.')).toBeInTheDocument()
  })

  it('is hidden when the page says the user may not use it', () => {
    renderForm({ writingHelp: false, task: makeTask({ id: 42 }) })

    expect(screen.queryByRole('button', { name: 'Improve with AI' })).not.toBeInTheDocument()
  })
})
