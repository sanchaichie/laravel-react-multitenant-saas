import { ApiError, apiFetch, returnToAdmin } from '@/lib/api'
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
  // Read now: on a 401, apiFetch may put the admin's token back before the catch below runs.
  const inSupportView = useAuth.getState().adminToken !== null

  // The whole app waits for this: give up after 10s (an API that accepts the
  // connection but never answers) and show RequireAuth's Retry instead.
  const data = await loadMe().catch(async (error: unknown) => {
    // A support view that ended while the tab was closed: wait for the admin's own session.
    if (error instanceof ApiError && error.status === 401 && inSupportView) {
      // apiFetch started it; if it has already finished, there's nothing left to wait for.
      if (useAuth.getState().adminToken !== null) {
        await returnToAdmin()
      }
      return null
    }
    throw error
  })
  if (data === null) {
    return
  }

  // Unless the session changed meanwhile (logged out, or in as someone else).
  if (useAuth.getState().token === token) {
    useAuth.getState().setUser(data)
  }
}

function loadMe(): Promise<User> {
  return apiFetch<{ data: User }>('/api/me', { signal: AbortSignal.timeout(10_000) }).then(({ data }) => data)
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
