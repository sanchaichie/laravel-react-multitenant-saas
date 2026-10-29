import { create } from 'zustand'
import { persist } from 'zustand/middleware'
import { queryClient } from '@/lib/queryClient'
import type { Organization, User } from '@/types/api'

interface AuthState {
  token: string | null
  user: User | null
  /** Just logged in, in more than one organization: ask which one first (RequireGuest). */
  chooseOrganization: boolean
  setSession: (token: string, user: User, options?: { chooseOrganization?: boolean }) => void
  setUser: (user: User) => void
  setCurrentOrganization: (organization: Organization) => void
  organizationChosen: () => void
  clearSession: () => void
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
    (set) => ({
      token: null,
      user: null,
      chooseOrganization: false,
      setSession: (token, user, { chooseOrganization = false } = {}) => set({ token, user, chooseOrganization }),
      setUser: (user) => set({ user }),
      // The server already switched; this mirrors it (the answer to switch or create).
      setCurrentOrganization: (organization) =>
        set((state) => (state.user ? { user: { ...state.user, current_organization: organization } } : {})),
      organizationChosen: () => set({ chooseOrganization: false }),
      clearSession: () => {
        // Cached server data belongs to the session that just ended.
        queryClient.clear()
        set({ token: null, user: null, chooseOrganization: false })
      },
    }),
    // Only the token is saved; the user is always fresh from the API.
    { name: 'auth', partialize: (state) => ({ token: state.token }) },
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
