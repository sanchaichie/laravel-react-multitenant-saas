import type {
  AdminOrganization,
  AdminOrganizationDetail,
  AdminUser,
  AdminUserDetail,
  CursorPage,
  Paginated,
  PlatformAuditLogEntry,
  User,
} from '@/types/api'
import { freePlan, makeAiBudget } from './billing'
import { ada } from './utils'

/** Ada as a platform admin (logIn(adminAda)). */
export const adminAda: User = { ...ada, two_factor_enabled: true, is_platform_admin: true }

export function makeAdminUser(overrides: Partial<AdminUser> = {}): AdminUser {
  return {
    id: 7,
    name: 'Mia Chen',
    email: 'mia@example.com',
    email_verified: true,
    two_factor_enabled: false,
    is_platform_admin: false,
    suspended_at: null,
    organizations_count: 2,
    created_at: '2026-10-01T09:00:00.000000Z',
    ...overrides,
  }
}

export function makeAdminUserDetail(overrides: Partial<AdminUserDetail> = {}): AdminUserDetail {
  return {
    ...makeAdminUser(),
    devices_count: 1,
    last_active_at: '2026-10-20T08:30:00.000000Z',
    organizations: [
      { id: 1, name: 'Acme', role: { id: 1, name: 'Owner', is_owner: true }, joined_at: '2026-10-01T09:00:00.000000Z' },
      { id: 2, name: 'Beta', role: { id: 9, name: 'Member', is_owner: false }, joined_at: '2026-10-05T09:00:00.000000Z' },
    ],
    ...overrides,
  }
}

export function makeAdminOrganization(overrides: Partial<AdminOrganization> = {}): AdminOrganization {
  return { id: 1, name: 'Acme', members_count: 3, plan: 'free', created_at: '2026-10-01T09:00:00.000000Z', ...overrides }
}

export function makeAdminOrganizationDetail(overrides: Partial<AdminOrganizationDetail> = {}): AdminOrganizationDetail {
  return {
    id: 1,
    name: 'Acme',
    created_at: '2026-10-01T09:00:00.000000Z',
    plan: freePlan,
    subscription: null,
    ai_budget: makeAiBudget(),
    counts: { members: 2, tasks: 5, pending_invitations: 1 },
    members: [
      { id: 7, name: 'Mia Chen', email: 'mia@example.com', is_platform_admin: false, role: { id: 1, name: 'Owner', is_owner: true }, joined_at: '2026-10-01T09:00:00.000000Z' },
      { id: 8, name: 'Sam Lee', email: 'sam@example.com', is_platform_admin: false, role: { id: 3, name: 'Member', is_owner: false }, joined_at: '2026-10-02T09:00:00.000000Z' },
    ],
    members_limit: 100,
    ...overrides,
  }
}

/** A page of an admin search (Laravel's paginator). */
export function adminPage<T>(data: T[], { page = 1, lastPage = 1 } = {}): Paginated<T> {
  return {
    data,
    links: { first: null, last: null, prev: null, next: null },
    meta: { current_page: page, last_page: lastPage, per_page: 25, total: data.length, from: data.length ? 1 : null, to: data.length || null },
  }
}

let nextEntryId = 900

export function makePlatformLogEntry(overrides: Partial<PlatformAuditLogEntry> = {}): PlatformAuditLogEntry {
  return {
    id: nextEntryId++,
    action: 'admin.user_viewed',
    action_label: 'Viewed a user',
    actor: { id: adminAda.id, name: adminAda.name, email: adminAda.email },
    subject: { type: 'user', id: 7, label: 'mia@example.com' },
    metadata: {},
    ip_address: '127.0.0.1',
    created_at: '2026-10-29T14:05:00.000000Z',
    ...overrides,
  }
}

export function platformLogPage(data: PlatformAuditLogEntry[], nextCursor: string | null = null): CursorPage<PlatformAuditLogEntry> {
  return { data, meta: { next_cursor: nextCursor, prev_cursor: null, per_page: 25, path: '/api/admin/audit-log' } }
}

/** An admin route's 403 asking for two-factor (EnsurePlatformAdmin). */
export const twoFactorRequired = { message: 'Turn on two-factor authentication to use the admin area.', code: 'two_factor_required' }
export const twoFactorReauth = { message: 'Enter a code from your authenticator app to continue.', code: 'two_factor_reauth' }
