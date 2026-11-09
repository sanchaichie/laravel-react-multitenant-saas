import 'server-only'
import { cache } from 'react'
import { headers } from 'next/headers'
import { redirect } from 'next/navigation'
import type { OrganizationList, User } from '@shared/types/api'
import { serverApi } from './api'
import { ApiError } from './errors'
import { getToken } from './session'

/**
 * The logged-in user, or null (no cookie, or the API says the token is gone).
 * React's cache() runs it once per request however many components ask: the
 * layout, the page and its parts all share one /api/me call. Other failures
 * (API down) throw, and the nearest error.tsx shows them.
 */
export const getCurrentUser = cache(async (): Promise<User | null> => {
  if (!(await getToken())) {
    return null
  }
  try {
    const { data } = await serverApi<{ data: User }>('/api/me')
    return data
  } catch (error) {
    if (error instanceof ApiError && error.status === 401) {
      return null
    }
    throw error
  }
})

/**
 * For logged-in pages: the user, or off to log in. A stale cookie is cleared by
 * /session-expired (a page can't delete cookies while rendering). After logging in
 * it comes back to `from`: by default this page's URL with its query (`x-url`,
 * set by proxy.ts), so the layout and the page agree on it.
 */
export async function requireUser(from?: string): Promise<User> {
  const user = await getCurrentUser()
  if (!user) {
    const back = from ?? (await headers()).get('x-url') ?? '/'
    redirect(`/session-expired?redirect=${encodeURIComponent(back)}`)
  }

  return user
}

export const getOrganizations = cache(() => serverApi<OrganizationList>('/api/organizations'))
