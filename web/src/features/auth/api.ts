import { useMutation } from '@tanstack/react-query'
import { useNavigate } from 'react-router'
import { toast } from 'sonner'
import { ApiError, apiFetch } from '@/lib/api'
import { errorMessage } from '@/lib/apiErrors'
import { useAuth } from '@/stores/auth'
import type { AuthResponse, LoginPayload, RegisterPayload } from '@/types/api'

// Mutations, not queries: these change the session rather than read data. On
// success they store it, and RequireGuest (which reads the store) sends the user on.

export function useLogin() {
  const setSession = useAuth((s) => s.setSession)

  return useMutation({
    mutationFn: (payload: LoginPayload) =>
      apiFetch<AuthResponse>('/api/login', { method: 'POST', body: payload }),
    onSuccess: ({ token, user }) => setSession(token, user),
  })
}

export function useRegister() {
  const setSession = useAuth((s) => s.setSession)

  return useMutation({
    // The API only accepts lowercase emails; spare the user that error.
    mutationFn: (payload: RegisterPayload) =>
      apiFetch<AuthResponse>('/api/register', {
        method: 'POST',
        body: { ...payload, email: payload.email.toLowerCase() },
      }),
    onSuccess: ({ token, user }) => setSession(token, user),
  })
}

export function useLogout() {
  const clearSession = useAuth((s) => s.clearSession)
  const navigate = useNavigate()

  return useMutation({
    // A 401 (token already revoked) must not end the session ahead of onSettled.
    mutationFn: () => apiFetch<void>('/api/logout', { method: 'POST', endSessionOn401: false }),
    // Log out here even if the API call failed (e.g. the token was already revoked).
    // Both changes must land in one render: with only the token gone, RequireAuth
    // would send us to /login?redirect=<this page> as if the session had expired;
    // with only /login, RequireGuest would send a still-logged-in user back. The
    // router normally commits a navigation as a transition, which the store's
    // synchronous update would overtake; flushSync makes it render together with it.
    onSettled: (_data, error) => {
      clearSession()
      void navigate('/login', { replace: true, flushSync: true })

      // Not a 401 (already revoked): the token may still be valid on the server.
      if (error && !(error instanceof ApiError && error.status === 401)) {
        toast.warning('Logged out on this device', {
          description: `The server couldn't confirm it (${errorMessage(error)}), so your session may still be active there.`,
        })
      }
    },
  })
}
