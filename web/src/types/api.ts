// Shapes of the Laravel API's requests and responses (api/app/Http/Resources).

/** What a role allows (App\\Enums\\Permission). Only for showing controls: the API decides. */
export type Permission = 'tasks.create' | 'tasks.manage' | 'members.invite' | 'members.manage' | 'roles.manage'

/** A role where something has one (you, a member, an invitation). Roles are per organization. */
export interface Role {
  id: number
  name: string
  /** The built-in Owner role: every permission, can't be edited or deleted. */
  is_owner: boolean
}

/** A role on the roles page: what it allows and what you may do to it. */
export interface RoleDetails extends Role {
  /** Every permission for the owner role. */
  permissions: Permission[]
  members_count: number
  can: { update: boolean; delete: boolean }
}

export interface RolePayload {
  name: string
  permissions: Permission[]
}

/** One entry of the permission catalogue (GET /api/permissions). */
export interface PermissionInfo {
  value: Permission
  label: string
  /** The area it belongs to, e.g. "Tasks". */
  group: string
}

/** An organization as the current user sees it: with their role in it. */
export interface Organization {
  id: number
  name: string
  role: Role
  permissions: Permission[]
  created_at: string
}

export interface OrganizationList {
  data: Organization[]
  meta: { current_organization_id: number | null }
}

export interface User {
  id: number
  name: string
  email: string
  email_verified: boolean
  /** Kept on the server (switching changes it); null once removed from it. */
  current_organization: Organization | null
  created_at: string
}

export interface AuthResponse {
  user: User
  token: string
}

export interface LoginPayload {
  email: string
  password: string
}

export interface RegisterPayload {
  name: string
  email: string
  password: string
  password_confirmation: string
}

/** Body of an error response: a message, and per-field messages on a 422. */
export interface ApiErrorBody {
  message?: string
  /** A machine-readable reason, e.g. 'organization_changed' (409). */
  code?: string
  errors?: Record<string, string[]>
}

/** A member of the current organization, with what the requesting user may do to them. */
export interface Member {
  id: number
  name: string
  email: string
  role: Role
  joined_at: string
  is_me: boolean
  /** Roles this member may be changed to (empty: no role change allowed). */
  assignable_roles: Role[]
  can: { update: boolean; delete: boolean }
}

export interface Invitation {
  id: number
  email: string
  /** Null once its role was deleted: it can't be accepted, only revoked or replaced. */
  role: Role | null
  invited_by: string | null
  expires_at: string
  expired: boolean
  created_at: string
  can: { delete: boolean }
}

export interface InvitationList {
  data: Invitation[]
  /** The roles the requesting user may invite with (the API's policy). */
  meta: {
    invitable_roles: Role[]
    /** The one the invite form starts on: the least powerful of them. */
    default_role_id: number | null
  }
}

/** What the join page shows before accepting. */
export interface InvitationPreview {
  organization: { name: string }
  invited_by: string | null
  role: Role
  email: string
  expires_at: string
  already_member: boolean
}

/** A page of a Laravel paginated resource collection. */
export interface Paginated<T> {
  data: T[]
  links: { first: string | null; last: string | null; prev: string | null; next: string | null }
  meta: { current_page: number; last_page: number; per_page: number; total: number; from: number | null; to: number | null }
}

export type TaskStatus = 'todo' | 'in_progress' | 'done'
export type TaskPriority = 'low' | 'medium' | 'high'
export type TaskSort = 'newest' | 'due' | 'priority'

/** Someone a task points at (assignee, creator): null once they're gone. */
export interface TaskPerson {
  id: number
  name: string
}

export interface Task {
  id: number
  title: string
  description: string | null
  status: TaskStatus
  priority: TaskPriority
  /** A plain date, 'YYYY-MM-DD' (no time zone). */
  due_date: string | null
  /** Due before the API's today and not done: the app never compares dates itself. */
  is_overdue: boolean
  assignee: TaskPerson | null
  creator: TaskPerson | null
  created_at: string
  updated_at: string
  can: { update: boolean; delete: boolean }
}

/** What create sends; update (PATCH) sends only the fields changed in the form. */
export interface TaskPayload {
  title: string
  description: string | null
  status: TaskStatus
  priority: TaskPriority
  due_date: string | null
  assignee_id: number | null
}

/** The list's filters, as they appear in the URL (GET /api/tasks takes the same). */
export interface TaskFilters {
  status?: TaskStatus
  priority?: TaskPriority
  /** A member's id, 'me', or 'none' (unassigned). */
  assignee?: string
  overdue?: boolean
  search?: string
  sort?: TaskSort
  page?: number
}
