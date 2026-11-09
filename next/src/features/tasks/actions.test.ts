import { beforeEach, describe, expect, it, vi } from 'vitest'
import { makeTask } from '@/test/fixtures'
import { json, mockFetch, nextCache, RedirectError } from '@/test/next'
import { createTask, deleteTask, setTaskStatus, updateTask } from './actions'
import { toValues } from './schema'

vi.mock('next/headers', async () => (await import('@/test/next')).nextHeaders)
vi.mock('next/navigation', async () => (await import('@/test/next')).nextNavigation)
vi.mock('next/cache', async () => (await import('@/test/next')).nextCache)

function form(values: Record<string, string>): FormData {
  const data = new FormData()
  for (const [key, value] of Object.entries(values)) {
    data.set(key, value)
  }
  return data
}

const filled = { title: 'Ship it', description: '', status: 'todo', priority: 'high', assignee_id: '7', due_date: '2026-12-01' }
const organizationChanged = () => json({ message: 'Your organization changed.', code: 'organization_changed' }, 409)

beforeEach(() => nextCache.revalidatePath.mockClear())

describe('createTask', () => {
  it('sends the task with X-Organization-Id and goes to it', async () => {
    const calls = mockFetch({ 'POST /api/tasks': () => json({ data: makeTask({ id: 55 }) }, 201) })

    await expect(createTask(10, {}, form(filled))).rejects.toEqual(new RedirectError('/tasks/55'))

    expect(JSON.parse(calls[0].init.body as string)).toEqual({
      title: 'Ship it',
      description: null,
      status: 'todo',
      priority: 'high',
      assignee_id: 7,
      due_date: '2026-12-01',
    })
    expect(new Headers(calls[0].init.headers).get('x-organization-id')).toBe('10')
    expect(nextCache.revalidatePath).toHaveBeenCalledWith('/', 'layout')
  })

  it('puts a 422 on its fields and keeps what was typed', async () => {
    mockFetch({ 'POST /api/tasks': () => json({ message: 'x', errors: { assignee_id: ['The selected assignee is invalid.'] } }, 422) })

    const state = await createTask(10, {}, form(filled))

    expect(state.errors).toEqual({ assignee_id: 'The selected assignee is invalid.' })
    expect(state.values?.title).toBe('Ship it')
  })

  it('checks the fields before calling the API', async () => {
    const calls = mockFetch({})

    const state = await createTask(10, {}, form({ ...filled, title: '  ', due_date: '2026-13-45' }))

    expect(state.errors).toMatchObject({ title: 'Enter a title.', due_date: 'Enter a valid date.' })
    expect(calls).toHaveLength(0)
  })

  it('says when another tab switched organization', async () => {
    mockFetch({ 'POST /api/tasks': organizationChanged })

    const state = await createTask(10, {}, form(filled))

    expect(state.organizationChanged).toBe(true)
  })
})

describe('updateTask', () => {
  const task = makeTask({ id: 42, title: 'Old title', priority: 'low', assignee: { id: 7, name: 'Mia' } })
  const before = toValues(task)

  it('sends only the fields changed in the form', async () => {
    const calls = mockFetch({ 'PATCH /api/tasks/42': () => json({ data: task }) })

    await expect(updateTask(10, 42, before, {}, form({ ...before, title: 'New title', assignee_id: 'none' }))).rejects.toEqual(
      new RedirectError('/tasks/42'),
    )

    expect(JSON.parse(calls[0].init.body as string)).toEqual({ title: 'New title', assignee_id: null })
  })

  it('makes no request when nothing changed', async () => {
    const calls = mockFetch({})

    await expect(updateTask(10, 42, before, {}, form({ ...before }))).rejects.toEqual(new RedirectError('/tasks/42'))

    expect(calls).toHaveLength(0)
    expect(nextCache.revalidatePath).not.toHaveBeenCalled()
  })

  it('says when another tab switched organization', async () => {
    mockFetch({ 'PATCH /api/tasks/42': organizationChanged })

    const state = await updateTask(10, 42, before, {}, form({ ...before, title: 'New title' }))

    expect(state.organizationChanged).toBe(true)
  })
})

describe('setTaskStatus and deleteTask', () => {
  it('changes the status', async () => {
    const calls = mockFetch({ 'PATCH /api/tasks/42': () => json({ data: makeTask({ id: 42, status: 'done' }) }) })

    await expect(setTaskStatus(10, 42, 'done')).resolves.toEqual({ ok: true })
    expect(JSON.parse(calls[0].init.body as string)).toEqual({ status: 'done' })
  })

  it('refuses an unknown status without a request', async () => {
    const calls = mockFetch({})

    await expect(setTaskStatus(10, 42, 'archived' as never)).resolves.toMatchObject({ ok: false })
    expect(calls).toHaveLength(0)
  })

  it('deletes and goes back to the list', async () => {
    mockFetch({ 'DELETE /api/tasks/42': () => new Response(null, { status: 204 }) })

    await expect(deleteTask(10, 42)).rejects.toEqual(new RedirectError('/tasks'))
  })

  it('reports a refused delete', async () => {
    mockFetch({ 'DELETE /api/tasks/42': () => json({ message: 'This action is unauthorized.' }, 403) })

    await expect(deleteTask(10, 42)).resolves.toEqual({
      ok: false,
      message: 'This action is unauthorized.',
      organizationChanged: false,
    })
  })
})
