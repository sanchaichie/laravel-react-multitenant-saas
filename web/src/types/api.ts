// Shapes of the Laravel API's requests and responses (api/app/Http/Resources).

export interface User {
  id: number
  name: string
  email: string
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
  errors?: Record<string, string[]>
}
