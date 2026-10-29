import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useNavigate } from 'react-router'
import { toast } from 'sonner'
import { organizationsQuery } from '@/features/organizations/api'
import { ApiError, apiFetch } from '@/lib/api'
import { errorMessage } from '@/lib/apiErrors'
import { useAuth } from '@/stores/auth'
import type { AuthResponse, LoginPayload, OrganizationList, RegisterPayload } from '@/types/api'

// Mutations, not queries: these change the session rather than read data. On
// success they store it, and RequireGuest (which reads the store) sends the user on.

export function useLogin() {
  const setSession = useAuth((s) => s.setSession)
  const queryClient = useQueryClient()

  return useMutation({
    // Load the organizations with the new token before storing the session, so the
    // guard knows at once whether to ask which one (more than one). If that call
    // fails, log in anyway and skip the question.
    mutationFn: async (payload: LoginPayload) => {
      const session = await apiFetch<AuthResponse>('/api/login', { method: 'POST', body: payload })
      const organizations = await apiFetch<OrganizationList>('/api/organizations', { token: session.token }).catch(
        () => null,
      )

      return { ...session, organizations }
    },
    onSuccess: ({ token, user, organizations }) => {
      if (organizations) {
        queryClient.setQueryData(organizationsQuery.queryKey, organizations)
      }
      setSession(token, user, { chooseOrganization: (organizations?.data.length ?? 0) > 1 })
    },
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
