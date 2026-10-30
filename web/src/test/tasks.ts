import type { Member, Paginated, Task, TaskSummary, TaskSummaryResponse } from '@/types/api'
import { ada, adminRole, memberRole, ownerRole } from './utils'

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
    can: { update: true, delete: true, write_with_ai: true },
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
  role: ownerRole,
  joined_at: '2026-10-01T12:00:00.000000Z',
  is_me: true,
  assignable_roles: [],
  can: { update: false, delete: true },
}

export const mia: Member = {
  id: 7,
  name: 'Mia Member',
  email: 'mia@example.com',
  role: memberRole,
  joined_at: '2026-10-02T12:00:00.000000Z',
  is_me: false,
  assignable_roles: [adminRole],
  can: { update: true, delete: true },
}

/** A task summary as the API returns it: a reviewable draft of task 42 unless overridden. */
export function makeTaskSummary(overrides: Partial<TaskSummary> = {}): TaskSummary {
  const id = overrides.id ?? nextId++
  return {
    id,
    task_id: 42,
    status: 'draft',
    summary: 'Write the onboarding doc for new hires.',
    key_points: ['Cover the first week', 'Link the handbook'],
    model: 'claude-haiku-5-5',
    stale: false,
    stuck: false,
    error: null,
    requested_by: { id: ada.id, name: ada.name },
    reviewed_by: null,
    reviewed_at: null,
    created_at: '2026-10-21T12:00:00.000000Z',
    can: { review: true },
    ...overrides,
  }
}

/** GET /api/tasks/{id}/summary for a task that has none yet (and you may ask for one). */
export const noSummary: TaskSummaryResponse = { data: null, approved: null, can: { regenerate: true } }
