import type { ReactNode } from 'react'
import { Link } from 'react-router'
import { ErrorState } from '@/components/ErrorState'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { errorMessage } from '@/lib/apiErrors'
import { useAuth } from '@/stores/auth'
import { adminRefusal } from './api'
import { ReconfirmForm } from './ReconfirmForm'

interface AdminGateProps {
  /** The page's query error, if any. */
  error: unknown
  /** Not found before asking the API (a malformed id in the URL). */
  notFound?: boolean
  onRetry: () => void
  /** The page, once there's nothing in the way. */
  children: ReactNode
}

/**
 * What stands between an admin page and its data, shown in place of the page (a
 * wrapper with a default slot, with Nuxt's route middleware written as render code):
 * not an admin or a 404 → "Page not found", the same as any unknown address, so
 * nothing tells others the area exists; no two-factor → how to turn it on; a stale
 * code → a fresh one, then the queries retry; anything else → Retry.
 */
export function AdminGate({ error, notFound = false, onRetry, children }: AdminGateProps) {
  const isAdmin = useAuth((s) => s.user?.is_platform_admin === true)
  const refusal = adminRefusal(error)

  if (!isAdmin || notFound || refusal === 'not_found') {
    return (
      <ErrorState title="Page not found" description="There's nothing at this address.">
        <title>Page not found · React SaaS</title>
        <Button asChild>
          <Link to="/">Go home</Link>
        </Button>
      </ErrorState>
    )
  }

  if (refusal === 'two_factor_required') {
    return (
      <ErrorState title="Turn on two-factor to use the admin area" description="Set it up in your account settings, then come back.">
        <Button asChild>
          <Link to="/account">Account settings</Link>
        </Button>
      </ErrorState>
    )
  }

  if (refusal === 'two_factor_reauth') {
    return (
      <div className="flex justify-center py-12">
        <Card className="w-full max-w-sm">
          <CardHeader>
            <CardTitle>
              <h1>Confirm it's you</h1>
            </CardTitle>
            <CardDescription>Enter a code from your authenticator app to continue.</CardDescription>
          </CardHeader>
          <CardContent>
            <ReconfirmForm />
          </CardContent>
        </Card>
      </div>
    )
  }

  if (error) {
    return (
      <ErrorState title="Could not load this page" description={errorMessage(error)}>
        <Button variant="outline" onClick={onRetry}>
          Retry
        </Button>
      </ErrorState>
    )
  }

  return children
}
