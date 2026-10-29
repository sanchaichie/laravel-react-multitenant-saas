import type { Member, Paginated, Task } from '@/types/api'
import { ada } from './utils'

let nextId = 100

/** A task as the API returns it; owner-like `can` unless overridden. */
export function makeTask(overrides: Partial<Task> = {}): Task {
  const id = overrides.id ?? nextId++
  return {
    id,
    title: `Task ${id}`,
    description: null,
    status: 'todo',
    priority: 'medium',
    due_date: null,
    is_overdue: false,
    assignee: null,
    creator: { id: ada.id, name: ada.name },
    created_at: '2026-10-20T12:00:00.000000Z',
    updated_at: '2026-10-21T12:00:00.000000Z',
    can: { update: true, delete: true },
    ...overrides,
  }
}

/** GET /api/tasks's answer for one page. */
export function taskPage(tasks: Task[], { page = 1, lastPage = 1, total = tasks.length } = {}): Paginated<Task> {
  return {
    data: tasks,
    links: { first: null, last: null, prev: null, next: null },
    meta: { current_page: page, last_page: lastPage, per_page: 20, total, from: tasks.length ? 1 : null, to: tasks.length || null },
  }
}

export const meMember: Member = {
  id: ada.id,
  name: ada.name,
  email: ada.email,
  role: 'owner',
  joined_at: '2026-10-01T12:00:00.000000Z',
  is_me: true,
  assignable_roles: [],
  can: { update: false, delete: true },
}

export const mia: Member = {
  id: 7,
  name: 'Mia Member',
  email: 'mia@example.com',
  role: 'member',
  joined_at: '2026-10-02T12:00:00.000000Z',
  is_me: false,
  assignable_roles: ['admin'],
  can: { update: true, delete: true },
}
