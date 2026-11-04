import { create } from 'zustand'
import { persist } from 'zustand/middleware'
import { queryClient } from '@/lib/queryClient'
import type { Organization, User } from '@/types/api'

export interface SupportViewEnded {
  /** The viewed user (0: unknown, go to the user list). */
  userId: number
  /** Expired or ended on the server (says so), rather than Stop viewing. */
  expired: boolean
}

interface AuthState {
  token: string | null
  user: User | null
  /** Just logged in, in more than one organization: ask which one first (RequireGuest). */
  chooseOrganization: boolean
  /**
   * In a platform admin's read-only support view (token is the user's session): the
   * admin's own token, put back when it ends. Saved like the token, so a reload
   * keeps both.
   */
  adminToken: string | null
  /** A support view just ended: whose admin page to go back to, and whether it ended on its own (expired). */
  supportViewEnded: SupportViewEnded | null
  setSession: (token: string, user: User, options?: { chooseOrganization?: boolean }) => void
  setUser: (user: User) => void
  setCurrentOrganization: (organization: Organization) => void
  organizationChosen: () => void
  clearSession: () => void
  /** Swap in a support view's token and user, keeping the admin's token aside. */
  beginSupportView: (token: string, user: User) => void
  /** Back to the admin's own token (and user, once loaded). `ended`: it ended on its own. */
  endSupportView: (adminUser: User | null, ended?: SupportViewEnded) => void
  supportViewEndedSeen: () => void
}

/**
 * The session: the API token (kept in localStorage, so a reload stays logged in)
 * and the current user (loaded from /api/me at startup, see bootstrapSession),
 * which carries the current organization. Read it with a selector, e.g.
 * useAuth(s => s.user), so a component re-renders only when that slice changes;
 * outside React, use useAuth.getState().
 */
export const useAuth = create<AuthState>()(
  persist(
    (set, get) => ({
      token: null,
      user: null,
      chooseOrganization: false,
      adminToken: null,
      supportViewEnded: null,
      setSession: (token, user, { chooseOrganization = false } = {}) => set({ token, user, chooseOrganization }),
      setUser: (user) => set({ user }),
      // The server already switched; this mirrors it (the answer to switch or create).
      setCurrentOrganization: (organization) =>
        set((state) => (state.user ? { user: { ...state.user, current_organization: organization } } : {})),
      organizationChosen: () => set({ chooseOrganization: false }),
      clearSession: () => {
        // Cached server data belongs to the session that just ended.
        queryClient.clear()
        set({ token: null, user: null, chooseOrganization: false, adminToken: null, supportViewEnded: null })
      },
      // Two identities, one store: cached data belongs to whoever it was loaded as.
      beginSupportView: (token, user) => {
        queryClient.clear()
        set({ adminToken: get().adminToken ?? get().token, token, user, chooseOrganization: false, supportViewEnded: null })
      },
      endSupportView: (adminUser, ended) => {
        // Already back (Stop and a 401 racing, or two Stop buttons): a second swap would drop the token.
        if (get().adminToken === null) {
          return
        }
        queryClient.clear()
        set({ token: get().adminToken, adminToken: null, user: adminUser, supportViewEnded: ended ?? null })
      },
      supportViewEndedSeen: () => set({ supportViewEnded: null }),
    }),
    // Only the tokens are saved; the user is always fresh from the API.
    { name: 'auth', partialize: (state) => ({ token: state.token, adminToken: state.adminToken }) },
  ),
)

/** Logged in: a token, and the user it belongs to. */
export const selectIsLoggedIn = (state: AuthState) => state.token !== null && state.user !== null

/**
 * The organization the user is working in, with their role, or null. Derived from
 * the user rather than stored twice, so the sidebar and the pages always agree.
 */
export function useCurrentOrganization(): Organization | null {
  return useAuth((s) => s.user?.current_organization ?? null)
}
