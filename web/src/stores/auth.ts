import { create } from 'zustand'
import { persist } from 'zustand/middleware'
import { queryClient } from '@/lib/queryClient'
import type { User } from '@/types/api'

interface AuthState {
  token: string | null
  user: User | null
  setSession: (token: string, user: User) => void
  setUser: (user: User) => void
  clearSession: () => void
}

/**
 * The session: the API token (kept in localStorage, so a reload stays logged in)
 * and the current user (loaded from /api/me at startup, see bootstrapSession).
 * Read it with a selector, e.g. useAuth(s => s.user), so a component re-renders
 * only when that slice changes; outside React, use useAuth.getState().
 */
export const useAuth = create<AuthState>()(
  persist(
    (set) => ({
      token: null,
      user: null,
      setSession: (token, user) => set({ token, user }),
      setUser: (user) => set({ user }),
      clearSession: () => {
        // Cached server data belongs to the session that just ended.
        queryClient.clear()
        set({ token: null, user: null })
      },
    }),
    // Only the token is saved; the user is always fresh from the API.
    { name: 'auth', partialize: (state) => ({ token: state.token }) },
  ),
)

/** Logged in: a token, and the user it belongs to. */
export const selectIsLoggedIn = (state: AuthState) => state.token !== null && state.user !== null
