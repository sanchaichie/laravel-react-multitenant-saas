import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useNavigate } from 'react-router'
import { toast } from 'sonner'
import { organizationsQuery } from '@/features/organizations/api'
import { ApiError, apiFetch } from '@/lib/api'
import { errorMessage } from '@/lib/apiErrors'
import { useAuth } from '@/stores/auth'
import { reloadUser } from './session'
import type {
  AuthResponse,
  LoginPayload,
  LoginResult,
  OrganizationList,
  RegisterPayload,
  TwoFactorChallenge,
  User,
} from '@/types/api'

// Mutations, not queries: these change the session rather than read data. On
// success they store it, and RequireGuest (which reads the store) sends the user on.

/**
 * A new session (login, password reset) with the user's organizations loaded first,
 * so the guard knows at once whether to ask which one (more than one). If that call
 * fails, log in anyway and skip the question. A plain function, not a hook: it needs
 * no React state, only the token it was given.
 */
async function withOrganizations(session: AuthResponse) {
  const organizations = await apiFetch<OrganizationList>('/api/organizations', { token: session.token }).catch(() => null)

  return { ...session, organizations }
}

type Session = Awaited<ReturnType<typeof withOrganizations>>

/**
 * A login or reset answer: with a token, load the organizations; with two-factor on,
 * the challenge as it is, for the page to ask for a code.
 */
async function sessionOrChallenge(result: LoginResult): Promise<Session | TwoFactorChallenge> {
  // `'challenge' in result` narrows the union: TypeScript knows which shape each branch has.
  return 'challenge' in result ? result : withOrganizations(result)
}

/** Store a session from withOrganizations(); RequireGuest then sends the user on. */
function useStartSession() {
  const setSession = useAuth((s) => s.setSession)
  const queryClient = useQueryClient()

  return (result: Session | TwoFactorChallenge) => {
    // A challenge: nothing to store yet, the page shows the code step.
    if ('challenge' in result) {
      return
    }
    const { token, user, organizations } = result
    if (organizations) {
      queryClient.setQueryData(organizationsQuery.queryKey, organizations)
    }
    setSession(token, user, { chooseOrganization: (organizations?.data.length ?? 0) > 1 })
  }
}

export function useLogin() {
  const startSession = useStartSession()

  return useMutation({
    mutationFn: async (payload: LoginPayload) =>
      sessionOrChallenge(await apiFetch<LoginResult>('/api/login', { method: 'POST', body: payload })),
    onSuccess: startSession,
  })
}

/** A code from the authenticator app, or a recovery code, for a login's challenge. */
export type TwoFactorAnswer = { challenge: string } & ({ code: string } | { recovery_code: string })

/** The second step of a login or reset with two-factor on; logs in like a login. */
export function useCompleteTwoFactor() {
  const startSession = useStartSession()

  return useMutation({
    mutationFn: async (body: TwoFactorAnswer) =>
      withOrganizations(await apiFetch<AuthResponse>('/api/two-factor-challenge', { method: 'POST', body })),
    onSuccess: startSession,
  })
}

/** Always the same answer, whether an account uses the address or not. */
export function useForgotPassword() {
  return useMutation({
    mutationFn: (email: string) =>
      apiFetch<{ message: string }>('/api/forgot-password', { method: 'POST', body: { email: email.toLowerCase() } }),
  })
}

/** The link's token and email plus the new password; logs in like a login (a challenge with two-factor on). */
export function useResetPassword() {
  const startSession = useStartSession()

  return useMutation({
    mutationFn: async (payload: { token: string; email: string; password: string; password_confirmation: string }) =>
      sessionOrChallenge(await apiFetch<LoginResult>('/api/reset-password', { method: 'POST', body: payload })),
    onSuccess: startSession,
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
    // `to`: where to go after, e.g. back to an invitation as another account (default /login).
    mutationFn: (_to?: string) => apiFetch<void>('/api/logout', { method: 'POST', endSessionOn401: false }),
    // Log out here even if the API call failed (e.g. the token was already revoked).
    // Both changes must land in one render: with only the token gone, RequireAuth
    // would send us to /login?redirect=<this page> as if the session had expired;
    // with only /login, RequireGuest would send a still-logged-in user back. The
    // router normally commits a navigation as a transition, which the store's
    // synchronous update would overtake; flushSync makes it render together with it.
    onSettled: (_data, error, to) => {
      clearSession()
      void navigate(to ?? '/login', { replace: true, flushSync: true })

      // Not a 401 (already revoked): the token may still be valid on the server.
      if (error && !(error instanceof ApiError && error.status === 401)) {
        toast.warning('Logged out on this device', {
          description: `The server couldn't confirm it (${errorMessage(error)}), so your session may still be active there.`,
        })
      }
    },
  })
}

/** The four parts of the link in the verification email, as /verify-email receives them. */
export interface VerificationLink {
  id: string
  hash: string
  expires: string
  signature: string
}

/** Post the emailed link back to the API (it checks the signature and expiry). */
export function useVerifyEmail() {
  const setUser = useAuth((s) => s.setUser)

  return useMutation({
    mutationFn: ({ id, hash, expires, signature }: VerificationLink) =>
      apiFetch<{ data: User }>(
        `/api/email/verify/${encodeURIComponent(id)}/${encodeURIComponent(hash)}?${new URLSearchParams({ expires, signature })}`,
        { method: 'POST' },
      ),
    onSuccess: ({ data }) => setUser(data),
  })
}

export function useResendVerification() {
  return useMutation({
    mutationFn: () =>
      apiFetch<{ message: string; email_verified: boolean }>('/api/email/verification-notification', { method: 'POST' }),
    // Verified meanwhile (another tab or device): reload the user, so the banner goes.
    onSuccess: async (data) => {
      if (data.email_verified) {
        await reloadUser()
      }
    },
  })
}
