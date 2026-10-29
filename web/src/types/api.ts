// Shapes of the Laravel API's requests and responses (api/app/Http/Resources).

export type OrganizationRole = 'owner' | 'admin' | 'member'

/** What a role allows (App\\Enums\\Permission). Only for showing controls: the API decides. */
export type Permission = 'members.invite' | 'members.manage' | 'organization.manage'

/** An organization as the current user sees it: with their role in it. */
export interface Organization {
  id: number
  name: string
  role: OrganizationRole
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
  role: OrganizationRole
  joined_at: string
  is_me: boolean
  /** Roles this member may be changed to (empty: no role change allowed). */
  assignable_roles: OrganizationRole[]
  can: { update: boolean; delete: boolean }
}

export interface Invitation {
  id: number
  email: string
  role: OrganizationRole
  invited_by: string | null
  expires_at: string
  expired: boolean
  created_at: string
  can: { delete: boolean }
}

export interface InvitationList {
  data: Invitation[]
  /** The roles the requesting user may invite with (the API's policy). */
  meta: { invitable_roles: OrganizationRole[] }
}

/** What the join page shows before accepting. */
export interface InvitationPreview {
  organization: { name: string }
  invited_by: string | null
  role: OrganizationRole
  email: string
  expires_at: string
  already_member: boolean
}
