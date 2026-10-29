import { useMutation } from '@tanstack/react-query'
import { Navigate, Outlet, useLocation } from 'react-router'
import { ErrorState } from '@/components/ErrorState'
import { Button } from '@/components/ui/button'
import { Spinner } from '@/components/ui/spinner'
import { bootstrapSession } from '@/features/auth/session'
import { errorMessage } from '@/lib/apiErrors'
import { useAuth } from '@/stores/auth'

/**
 * Wraps the routes that need a login (the Nuxt `auth` middleware). A redirect is
 * just rendering <Navigate>. It re-renders whenever the token changes, so when
 * apiFetch clears the session on a 401, this sends the user to /login on its own.
 */
export function RequireAuth() {
  const token = useAuth((s) => s.token)
  const user = useAuth((s) => s.user)
  const location = useLocation()
  // Hooks run on every render, before any early return (the rules of hooks).
  const retry = useMutation({ mutationFn: bootstrapSession })

  if (!token) {
    // Come back here after logging in.
    const redirect = encodeURIComponent(location.pathname + location.search)
    return <Navigate to={`/login?redirect=${redirect}`} replace />
  }

  // A token, but /api/me couldn't be loaded at startup (API down): offer to retry,
  // saying why the last retry failed, so a click that fails again isn't silent.
  if (!user) {
    return (
      <div className="flex min-h-svh items-center justify-center">
        <ErrorState
          title="Couldn't load your account"
          description={
            retry.isError
              ? errorMessage(retry.error)
              : 'Your session is saved, but the API isn\'t answering. Check that it\'s running, then try again.'
          }
        >
          <Button onClick={() => retry.mutate()} disabled={retry.isPending}>
            {retry.isPending && <Spinner />}
            Retry
          </Button>
        </ErrorState>
      </div>
    )
  }

  return <Outlet />
}
