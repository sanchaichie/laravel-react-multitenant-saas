import type { Member, Organization, Paginated, Role, Task, User } from '@shared/types/api'

// The same people and organizations as web/src/test (Ada owns Acme).

export const ownerRole: Role = { id: 1, name: 'Owner', is_owner: true }
export const viewerRole: Role = { id: 4, name: 'Viewer', is_owner: false }

export const acme: Organization = {
  id: 10,
  name: 'Acme',
  role: ownerRole,
  permissions: ['tasks.create', 'tasks.manage', 'members.invite', 'audit_log.view'],
  created_at: '2026-10-01T12:00:00.000000Z',
}

export const ada: User = {
  id: 1,
  name: 'Ada Lovelace',
  email: 'ada@example.com',
  email_verified: true,
  pending_email: null,
  two_factor_enabled: false,
  is_platform_admin: false,
  impersonated_by: null,
  current_organization: acme,
  created_at: '2026-10-01T12:00:00.000000Z',
}

/** Ada with a role that only reads tasks. */
export const adaAsViewer: User = { ...ada, current_organization: { ...acme, role: viewerRole, permissions: [] } }

let nextId = 100

export function makeTask(overrides: Partial<Task> = {}): Task {
  const id = overrides.id ?? nextId++
  return {
    id,
    title: `Task ${id}`,
    description: null,
    content_hidden: false,
    status: 'todo',
    priority: 'medium',
    due_date: null,
    is_overdue: false,
    assignee: null,
    creator: { id: ada.id, name: ada.name },
    created_at: '2026-10-20T12:00:00.000000Z',
    updated_at: '2026-10-21T12:00:00.000000Z',
    can: { update: true, delete: true, write_with_ai: true, chat: true },
    ...overrides,
  }
}

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
