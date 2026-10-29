import { apiFetch } from '@/lib/api'
import { useAuth } from '@/stores/auth'
import type { User } from '@/types/api'

/**
 * Loads the user the saved token belongs to. main.tsx runs it once before the
 * first render, so no page shows "logged in" with an unknown user; RequireAuth's
 * Retry runs it again when the API couldn't be reached.
 *
 * A 401 has already cleared the session (apiFetch). Any other failure keeps the
 * token (the API may just be down) and throws.
 */
export async function bootstrapSession(): Promise<void> {
  const token = useAuth.getState().token
  if (!token || useAuth.getState().user) {
    return
  }

  // The whole app waits for this: give up after 10s (an API that accepts the
  // connection but never answers) and show RequireAuth's Retry instead.
  const { data } = await apiFetch<{ data: User }>('/api/me', { signal: AbortSignal.timeout(10_000) })

  // Unless the session changed meanwhile (logged out, or in as someone else).
  if (useAuth.getState().token === token) {
    useAuth.getState().setUser(data)
  }
}

/**
 * Load the user again after something on the server changed them: leaving or
 * joining an organization (the current one moves), verifying the email.
 */
export async function reloadUser(): Promise<User> {
  const { data } = await apiFetch<{ data: User }>('/api/me')
  useAuth.getState().setUser(data)

  return data
}
