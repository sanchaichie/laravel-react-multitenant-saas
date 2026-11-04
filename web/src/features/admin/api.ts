import { infiniteQueryOptions, keepPreviousData, queryOptions, useInfiniteQuery, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useNavigate } from 'react-router'
import { ApiError, apiFetch } from '@/lib/api'
import { useAuth } from '@/stores/auth'
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

// User-level keys under ['admin'] (not ['org']: nothing here belongs to the current
// organization, so switching keeps them). Logging out clears every query.

export interface AdminSearch {
  q?: string
  page?: number
}

export function adminSearchParams({ q, page }: AdminSearch): URLSearchParams {
  const params = new URLSearchParams()
  if (q) params.set('q', q)
  if (page && page > 1) params.set('page', String(page))
  return params
}

/** Why an admin endpoint said no, as something the page can show (AdminGate). */
export type AdminRefusal = 'not_found' | 'two_factor_required' | 'two_factor_reauth'

export function adminRefusal(error: unknown): AdminRefusal | null {
  if (!(error instanceof ApiError)) return null
  if (error.status === 404) return 'not_found'
  if (error.status === 403 && error.data?.code === 'two_factor_required') return 'two_factor_required'
  if (error.status === 403 && error.data?.code === 'two_factor_reauth') return 'two_factor_reauth'
  return null
}

// A refusal is an answer, not a hiccup: retrying it would only delay the page.
const retry = (failureCount: number, error: unknown) => adminRefusal(error) === null && failureCount < 1

function withQuery(path: string, params: URLSearchParams): string {
  const query = params.toString()
  return query ? `${path}?${query}` : path
}

export const adminUsersQuery = (search: AdminSearch) =>
  queryOptions({
    queryKey: ['admin', 'users', search],
    queryFn: ({ signal }) => apiFetch<Paginated<AdminUser>>(withQuery('/api/admin/users', adminSearchParams(search)), { signal }),
  })

export const adminUserQuery = (id: number) =>
  queryOptions({
    queryKey: ['admin', 'users', 'detail', id],
    queryFn: ({ signal }) => apiFetch<{ data: AdminUserDetail }>(`/api/admin/users/${id}`, { signal }),
  })

export const adminOrganizationsQuery = (search: AdminSearch) =>
  queryOptions({
    queryKey: ['admin', 'organizations', search],
    queryFn: ({ signal }) =>
      apiFetch<Paginated<AdminOrganization>>(withQuery('/api/admin/organizations', adminSearchParams(search)), { signal }),
  })

export const adminOrganizationQuery = (id: number) =>
  queryOptions({
    queryKey: ['admin', 'organizations', 'detail', id],
    queryFn: ({ signal }) => apiFetch<{ data: AdminOrganizationDetail }>(`/api/admin/organizations/${id}`, { signal }),
  })

export const platformLogQuery = () =>
  infiniteQueryOptions({
    queryKey: ['admin', 'audit-log'],
    queryFn: ({ pageParam, signal }) =>
      apiFetch<CursorPage<PlatformAuditLogEntry>>(withQuery('/api/admin/audit-log', new URLSearchParams(pageParam ? { cursor: pageParam } : {})), { signal }),
    initialPageParam: null as string | null,
    getNextPageParam: (lastPage) => lastPage.meta.next_cursor,
  })

/** Only for platform admins: anyone else gets "not found" without asking the API. */
function useIsPlatformAdmin(): boolean {
  return useAuth((s) => s.user?.is_platform_admin === true)
}

export function useAdminUsers(search: AdminSearch) {
  const enabled = useIsPlatformAdmin()
  // The previous page or search stays on screen while the next one loads.
  return useQuery({ ...adminUsersQuery(search), enabled, retry, placeholderData: keepPreviousData })
}

/** `id` null (a malformed id in the URL) makes no request. */
export function useAdminUser(id: number | null) {
  const enabled = useIsPlatformAdmin() && id !== null
  // Each visit is a new "viewed" entry in the platform log: no refetch on remount within 30s.
  return useQuery({ ...adminUserQuery(id ?? 0), enabled, retry })
}

export function useAdminOrganizations(search: AdminSearch) {
  const enabled = useIsPlatformAdmin()
  return useQuery({ ...adminOrganizationsQuery(search), enabled, retry, placeholderData: keepPreviousData })
}

export function useAdminOrganization(id: number | null) {
  const enabled = useIsPlatformAdmin() && id !== null
  return useQuery({ ...adminOrganizationQuery(id ?? 0), enabled, retry })
}

export function usePlatformLog() {
  const enabled = useIsPlatformAdmin()
  return useInfiniteQuery({ ...platformLogQuery(), enabled, retry })
}

/**
 * A fresh code from the authenticator app on this device's token: admin routes ask
 * for one (two_factor_reauth) when the last is too old. Then every admin query
 * that was refused tries again (`refetch: false` from an action dialog).
 */
export function useReconfirmTwoFactor({ refetch = true }: { refetch?: boolean } = {}) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (code: string) => apiFetch<void>('/api/account/two-factor/reconfirm', { method: 'POST', body: { code } }),
    // Not from an action dialog: the retried action answers with the page's data, and a
    // refetch of a detail page would log another "viewed" entry.
    onSuccess: () => (refetch ? queryClient.invalidateQueries({ queryKey: ['admin'] }) : undefined),
  })
}

/** What an admin can do to a user (POST /api/admin/users/{id}/<path>). */
export type AdminUserAction = 'two-factor/reset' | 'sign-out' | 'suspend' | 'unsuspend'

/**
 * Act on a user, with a reason. The answer is the user as their page shows it:
 * written into that page's cache, and the lists and the platform log refetch.
 */
export function useAdminUserAction(userId: number, action: AdminUserAction) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (reason: string) => apiFetch<{ data: AdminUserDetail }>(`/api/admin/users/${userId}/${action}`, { method: 'POST', body: { reason } }),
    onSuccess: (answer) => {
      queryClient.setQueryData(adminUserQuery(userId).queryKey, answer)
      return Promise.all([
        queryClient.invalidateQueries({ queryKey: ['admin', 'users'], predicate: (query) => query.queryKey[2] !== 'detail' }),
        queryClient.invalidateQueries({ queryKey: ['admin', 'audit-log'] }),
      ])
    },
  })
}

/** Make an existing member Owner, with a reason. The answer is the organization's page. */
export function useMakeOwner(organizationId: number) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ userId, reason }: { userId: number; reason: string }) =>
      apiFetch<{ data: AdminOrganizationDetail }>(`/api/admin/organizations/${organizationId}/owners`, {
        method: 'POST',
        body: { user_id: userId, reason },
      }),
    onSuccess: (answer, { userId }) => {
      queryClient.setQueryData(adminOrganizationQuery(organizationId).queryKey, answer)
      return Promise.all([
        // Their roles changed: their page shows it next time.
        queryClient.invalidateQueries({ queryKey: adminUserQuery(userId).queryKey, refetchType: 'none' }),
        queryClient.invalidateQueries({ queryKey: ['admin', 'audit-log'] }),
      ])
    },
  })
}

/**
 * "View as user": a read-only session for the user (a reason, and a fresh code if
 * the API asks). Their /api/me is loaded with the new token before anything
 * switches, then the store swaps identities (the admin's token kept aside) and the
 * app opens at their home page. If loading them fails, the new session is ended.
 */
export function useStartSupportView(userId: number) {
  const beginSupportView = useAuth((s) => s.beginSupportView)
  const navigate = useNavigate()

  return useMutation({
    mutationFn: async (reason: string) => {
      const { token } = await apiFetch<{ token: string; expires_at: string }>(`/api/admin/users/${userId}/impersonate`, {
        method: 'POST',
        body: { reason },
      })
      try {
        const { data } = await apiFetch<{ data: User }>('/api/me', { token, endSessionOn401: false })
        return { token, user: data }
      } catch (error) {
        await apiFetch<void>('/api/logout', { method: 'POST', token, endSessionOn401: false }).catch(() => undefined)
        throw error
      }
    },
    // Here, not in mutate(): the admin page unmounts as the identity changes.
    // flushSync: the new identity and the new page render together (as in useLogout).
    onSuccess: ({ token, user }) => {
      beginSupportView(token, user)
      void navigate('/', { flushSync: true })
    },
  })
}

/**
 * Stop viewing: load the admin with their own token, swap back to it, go to the
 * user's admin page, and only then end the session on the server (with its token,
 * explicitly), so no request of this tab meets the ended token's 401 mid-swap. A
 * second Stop (the banner and the menu) finds the swap done and does nothing. If
 * the admin's own session is gone, log out; if the API is down, keep their token
 * and let RequireAuth offer Retry.
 */
export function useStopSupportView() {
  const navigate = useNavigate()

  return useMutation({
    mutationFn: async () => {
      const { adminToken, token, user } = useAuth.getState()
      if (adminToken === null) {
        return null
      }
      const { data } = await apiFetch<{ data: User }>('/api/me', { token: adminToken, endSessionOn401: false })
      return { admin: data, viewedId: user?.id ?? null, sessionToken: token }
    },
    onSuccess: (result) => {
      if (result === null) {
        return
      }
      // SupportViewEndedNotice goes to their admin page: its effect runs after any page's own
      // redirect on the organization change (a task page's), so it has the last word.
      useAuth.getState().endSupportView(result.admin, { userId: result.viewedId ?? 0, expired: false })
      endSession(result.sessionToken)
    },
    onError: (error) => {
      const { token } = useAuth.getState()
      if (error instanceof ApiError && error.status === 401) {
        useAuth.getState().clearSession()
        void navigate('/login', { replace: true, flushSync: true })
      } else {
        useAuth.getState().endSupportView(null)
      }
      endSession(token)
    },
  })
}

/** End a support view's session on the server; the tab no longer uses its token either way. */
function endSession(token: string | null) {
  if (token !== null) {
    void apiFetch<void>('/api/logout', { method: 'POST', token, endSessionOn401: false }).catch(() => undefined)
  }
}
