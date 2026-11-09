import { render, screen } from '@testing-library/react'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { TaskFilters, User } from '@shared/types/api'
import { ada, adaAsViewer, makeTask, meMember, taskPage } from '@/test/fixtures'
import { RedirectError } from '@/test/next'
import TasksPage from './page'

// The page is an async Server Component: call it like a function, then render what it returns.
const session = vi.hoisted(() => ({ user: null as User | null, tasks: vi.fn() }))

vi.mock('@/lib/dal', () => ({ requireUser: async () => session.user }))
vi.mock('@/lib/tasks', () => ({ getTasks: session.tasks, getMembers: async () => [meMember] }))
vi.mock('next/navigation', async () => ({
  ...(await import('@/test/next')).nextNavigation,
  useRouter: () => ({ replace: vi.fn(), push: vi.fn(), refresh: vi.fn() }),
  usePathname: () => '/tasks',
}))

async function renderPage(searchParams: Record<string, string> = {}) {
  render(await TasksPage({ searchParams: Promise.resolve(searchParams), params: Promise.resolve({}) }))
}

beforeEach(() => {
  session.user = ada
  session.tasks.mockReset()
})

describe('the tasks page', () => {
  it('asks the API for the filters in the URL and lists the tasks', async () => {
    session.tasks.mockResolvedValue(taskPage([makeTask({ title: 'Write the report', is_overdue: true, due_date: '2026-10-01' })]))

    await renderPage({ status: 'todo', overdue: '1', bogus: 'x' })

    expect(session.tasks).toHaveBeenCalledWith({ status: 'todo', overdue: true } satisfies TaskFilters)
    expect(screen.getByRole('link', { name: 'Write the report' })).toHaveAttribute('href', expect.stringMatching(/^\/tasks\/\d+$/))
    expect(screen.getAllByText('Overdue').length).toBeGreaterThan(0)
    expect(screen.getByRole('link', { name: 'New task' })).toHaveAttribute('href', '/tasks/new')
  })

  it('hides New task from a role without tasks.create', async () => {
    session.user = adaAsViewer
    session.tasks.mockResolvedValue(taskPage([]))

    await renderPage()

    expect(screen.getByText('No tasks yet')).toBeInTheDocument()
    expect(screen.getByText('Tasks created in this organization will show here.')).toBeInTheDocument()
    expect(screen.queryByRole('link', { name: 'New task' })).not.toBeInTheDocument()
  })

  it('offers to clear filters when nothing matches', async () => {
    session.tasks.mockResolvedValue(taskPage([]))

    await renderPage({ priority: 'high', sort: 'due' })

    expect(screen.getByText('No tasks match these filters')).toBeInTheDocument()
    // Clearing keeps the sort.
    expect(screen.getByRole('link', { name: 'Clear filters' })).toHaveAttribute('href', '/tasks?sort=due')
  })

  it('goes to the last page when the URL asks for one past the end', async () => {
    session.tasks.mockResolvedValue(taskPage([], { page: 5, lastPage: 2, total: 30 }))

    await expect(renderPage({ page: '5', status: 'done' })).rejects.toEqual(new RedirectError('/tasks?status=done&page=2'))
  })

  it('links to the previous and next pages', async () => {
    session.tasks.mockResolvedValue(taskPage([makeTask()], { page: 2, lastPage: 3, total: 50 }))

    await renderPage({ page: '2' })

    expect(screen.getByText('Page 2 of 3')).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Previous' })).toHaveAttribute('href', '/tasks')
    expect(screen.getByRole('link', { name: 'Next' })).toHaveAttribute('href', '/tasks?page=3')
  })

  it('says so without a current organization', async () => {
    session.user = { ...ada, current_organization: null }

    await renderPage()

    expect(screen.getByText('No organization selected')).toBeInTheDocument()
    expect(session.tasks).not.toHaveBeenCalled()
  })
})
