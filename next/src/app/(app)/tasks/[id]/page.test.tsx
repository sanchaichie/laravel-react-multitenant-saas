import { screen } from '@testing-library/react'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { Task } from '@shared/types/api'
import { ada, makeTask, meMember } from '@/test/fixtures'
import { renderWithClient } from '@/test/render'
import EditTaskPage from './edit/page'
import TaskPage from './page'

const data = vi.hoisted(() => ({ task: null as Task | null }))

vi.mock('@/lib/dal', () => ({ requireUser: async () => ada }))
vi.mock('@/lib/tasks', () => ({ getTask: async () => data.task, getMembers: async () => [meMember] }))
vi.mock('next/navigation', async () => ({
  ...(await import('@/test/next')).nextNavigation,
  useRouter: () => ({ replace: vi.fn(), refresh: vi.fn() }),
}))
vi.mock('@/features/tasks/actions', () => ({ createTask: vi.fn(), updateTask: vi.fn(), deleteTask: vi.fn(), setTaskStatus: vi.fn() }))

const params = Promise.resolve({ id: '42' })

beforeEach(() => {
  data.task = null
})

describe('Ask AI on the task page', () => {
  async function renderTask(task: Task) {
    data.task = task
    renderWithClient(await TaskPage({ params, searchParams: Promise.resolve({}) }))
  }

  it('shows with can.chat', async () => {
    await renderTask(makeTask({ id: 42 }))

    expect(screen.getByRole('button', { name: 'Ask AI' })).toBeInTheDocument()
  })

  it('is hidden without can.chat (a role without ai.use)', async () => {
    await renderTask(makeTask({ id: 42, can: { update: true, delete: true, write_with_ai: false, chat: false } }))

    expect(screen.queryByRole('button', { name: 'Ask AI' })).not.toBeInTheDocument()
  })

  it('is hidden when the content is hidden (a support view)', async () => {
    await renderTask(makeTask({ id: 42, content_hidden: true }))

    expect(screen.queryByRole('button', { name: 'Ask AI' })).not.toBeInTheDocument()
  })
})

describe('writing help on the Edit task page', () => {
  async function renderEdit(task: Task) {
    data.task = task
    renderWithClient(await EditTaskPage({ params, searchParams: Promise.resolve({}) }))
  }

  it('shows with can.write_with_ai', async () => {
    await renderEdit(makeTask({ id: 42 }))

    expect(screen.getByRole('button', { name: 'Improve with AI' })).toBeInTheDocument()
  })

  it('is hidden without it', async () => {
    await renderEdit(makeTask({ id: 42, can: { update: true, delete: true, write_with_ai: false, chat: true } }))

    expect(screen.queryByRole('button', { name: 'Improve with AI' })).not.toBeInTheDocument()
  })
})
