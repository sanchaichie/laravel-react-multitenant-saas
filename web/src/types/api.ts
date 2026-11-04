// Shapes of the Laravel API's requests and responses (api/app/Http/Resources).

/** What a role allows (App\\Enums\\Permission). Only for showing controls: the API decides. */
export type Permission =
  | 'tasks.create'
  | 'tasks.manage'
  | 'members.invite'
  | 'members.manage'
  | 'roles.manage'
  | 'audit_log.view'
  | 'ai.use'
  | 'ai_usage.view'
  | 'ai_budget.manage'

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
  /** A requested email change, until the link sent to the new address is followed. */
  pending_email: string | null
  /** Logging in asks for a code from an authenticator app. */
  two_factor_enabled: boolean
  /** Kept on the server (switching changes it); null once removed from it. */
  current_organization: Organization | null
  created_at: string
}

export interface AuthResponse {
  user: User
  token: string
}

/** Login or reset with two-factor on: no token yet, send the challenge back with a code. */
export interface TwoFactorChallenge {
  two_factor: true
  challenge: string
}

/** What logging in (or a password reset) answers: tell them apart with `'challenge' in result`. */
export type LoginResult = AuthResponse | TwoFactorChallenge

/** A started setup: the key for the authenticator app, and the link its QR code holds. */
export interface TwoFactorSetup {
  secret: string
  otpauth_uri: string
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
  /** write_with_ai: AI writing help in the edit form (ai.use, edit access, a verified email). */
  /** chat: your own AI chat about it (ai.use); sending also needs a verified email. */
  can: { update: boolean; delete: boolean; write_with_ai: boolean; chat: boolean }
}

/** pending (being generated) → draft (waiting for review) → approved or rejected; failed when it couldn't be generated. */
export type TaskSummaryStatus = 'pending' | 'draft' | 'approved' | 'rejected' | 'failed'

/** An AI summary of a task. Approving never changes the task. */
export interface TaskSummary {
  id: number
  task_id: number
  status: TaskSummaryStatus
  summary: string | null
  key_points: string[]
  /** The model that wrote it (null while pending). */
  model: string | null
  /** The task's title or description changed since it was written. */
  stale: boolean
  /** Pending for so long the job is likely lost: asking again replaces it. */
  stuck: boolean
  /** A short, generic reason, only when failed. */
  error: string | null
  requested_by: TaskPerson | null
  reviewed_by: TaskPerson | null
  reviewed_at: string | null
  created_at: string
  can: { review: boolean }
}

/** GET /api/tasks/{id}/summary: the latest summary (any status), and the approved one, which is the task's summary until a newer one is approved. */
export interface TaskSummaryResponse {
  data: TaskSummary | null
  approved: TaskSummary | null
  can: { regenerate: boolean }
}

/** Edits sent with an approval; a field left out keeps the AI's text. */
export interface TaskSummaryEdits {
  summary?: string
  key_points?: string[]
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

/** A page of a cursor-paginated list ("Load more"): no totals, just the next cursor. */
export interface CursorPage<T> {
  data: T[]
  meta: { next_cursor: string | null; prev_cursor: string | null; per_page: number; path: string }
}

/** One audit log entry (GET /api/audit-logs). Values are user content: render as text. */
export interface AuditLog {
  id: number
  /** e.g. "task.updated" (App\\Enums\\AuditAction). */
  action: string
  action_label: string
  /** Null for something no logged-in user did, or once they've deleted their account. */
  actor: { id: number; name: string } | null
  /** What it was about, with its name at the time (it may be gone since). */
  subject: { type: string; id: number | null; label: string | null } | null
  changes: Record<string, { old: unknown; new: unknown }>
  metadata: Record<string, unknown>
  /** Only for owners. */
  ip_address?: string | null
  created_at: string
}

/** One entry of the action catalogue (GET /api/audit-log/actions). */
export interface AuditActionInfo {
  value: string
  label: string
  group: string
}

/** The audit log's filters, as in the URL and GET /api/audit-logs. */
export interface AuditFilters {
  action?: string
  /** A member's id. */
  actor?: string
  /** Days (YYYY-MM-DD), read in the API's time zone. */
  from?: string
  to?: string
}

/** The current month's AI budget (GET /api/ai-usage, PUT /api/ai-budget). Amounts in USD. */
export interface AiBudget {
  limit_usd: number
  /** No own budget: the app-wide default applies. */
  is_default: boolean
  /** What the organization set; null uses the default. 0 turns AI off. */
  own_limit_usd: number | null
  default_limit_usd: number
  spent_usd: number
  remaining_usd: number
}

/** Calls, tokens and cost of one slice of a month's AI usage. */
export interface AiUsageRow {
  calls: number
  failed: number
  input_tokens: number
  output_tokens: number
  cost_usd: number
}

/** One month (UTC) of the current organization's AI usage. */
export interface AiUsageReport {
  /** YYYY-MM */
  month: string
  totals: AiUsageRow
  by_feature: Array<AiUsageRow & { feature: string }>
  /** user null: work started without a person, or by one whose account is gone. */
  by_user: Array<AiUsageRow & { user: { id: number; name: string } | null }>
  /** day: YYYY-MM-DD (UTC) */
  by_day: Array<AiUsageRow & { day: string }>
  /** Always this month's, whichever month is shown. */
  budget: AiBudget
  can: { manage_budget: boolean }
}

/** What AI writing help does with a task's description. */
export type WritingHelpMode = 'improve' | 'expand' | 'shorten'

/** One message of your private AI chat about a task (GET /api/tasks/{id}/chat). */
export interface TaskChatMessage {
  id: number
  role: 'user' | 'assistant'
  content: string
  /** An answer is stopped (cancelled, or still being written), failed, or complete. */
  status: 'complete' | 'stopped' | 'failed'
  created_at: string
}

/** One of your signed-in devices (an API token). Never the token itself. */
export interface Device {
  id: number
  /** e.g. "Chrome on macOS" */
  name: string
  ip_address: string | null
  last_used_at: string | null
  created_at: string
  /** The device this request came from. */
  is_current: boolean
}
