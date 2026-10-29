// Shapes of the Laravel API's requests and responses (api/app/Http/Resources).

export type OrganizationRole = 'owner' | 'admin' | 'member'

/** An organization as the current user sees it: with their role in it. */
export interface Organization {
  id: number
  name: string
  role: OrganizationRole
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
