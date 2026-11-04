import { useEffect, useRef } from 'react'
import { useMutation } from '@tanstack/react-query'
import { CircleCheckIcon } from 'lucide-react'
import { Link, useSearchParams } from 'react-router'
import { ErrorState } from '@/components/ErrorState'
import { Button } from '@/components/ui/button'
import { Spinner } from '@/components/ui/spinner'
import { AuthCard } from '@/features/auth/AuthCard'
import { apiFetch } from '@/lib/api'
import { errorMessage } from '@/lib/apiErrors'
import { useAuth } from '@/stores/auth'

interface UndoLink {
  id: string
  at: string
  expires: string
  signature: string
}

/**
 * The "this wasn't me" link from the "two-factor was turned on" email. No login
 * needed: someone else's phone may be the only way in. Two-factor goes, every device
 * is signed out (this one too), and a password reset link goes to the account's email.
 */
export function RevertTwoFactorPage() {
  const [searchParams] = useSearchParams()
  const link = readLink(searchParams)
  const clearSession = useAuth((s) => s.clearSession)
  const revert = useMutation({
    mutationFn: ({ id, at, expires, signature }: UndoLink) =>
      apiFetch<{ message: string }>(
        `/api/account/two-factor/revert/${id}/${at}?${new URLSearchParams({ expires, signature })}`,
        // Sent without logging anyone out on a 401: this page works logged out.
        { method: 'POST', endSessionOn401: false },
      ),
    // Every token was revoked on the server, this tab's too.
    onSuccess: () => clearSession(),
  })

  // Once, even when StrictMode mounts the page twice (see VerifyEmailPage).
  const posted = useRef(false)
  const { mutate } = revert
  useEffect(() => {
    if (link && !posted.current) {
      posted.current = true
      mutate(link)
    }
  }, [link, mutate])

  if (!link || revert.isError) {
    return (
      <AuthCard title="Turn off two-factor authentication">
        <title>Turn off two-factor authentication · React SaaS</title>
        <ErrorState
          title="This link doesn't work"
          description={
            link ? `${errorMessage(revert.error)} These links work for 7 days, once.` : 'Open the link from the email again.'
          }
        >
          <Button asChild>
            <Link to="/login">Go to log in</Link>
          </Button>
        </ErrorState>
      </AuthCard>
    )
  }

  if (revert.isSuccess) {
    return (
      <AuthCard title="Two-factor authentication is off">
        <title>Two-factor authentication is off · React SaaS</title>
        <div className="grid justify-items-center gap-4 py-2 text-center">
          <CircleCheckIcon className="size-8 text-muted-foreground" aria-hidden />
          <p className="text-sm text-muted-foreground">{revert.data.message}</p>
          <Button asChild>
            <Link to="/login">Go to log in</Link>
          </Button>
        </div>
      </AuthCard>
    )
  }

  return (
    <AuthCard title="Turn off two-factor authentication">
      <div className="flex justify-center py-6">
        <Spinner className="size-6 text-muted-foreground" />
      </div>
    </AuthCard>
  )
}

function readLink(params: URLSearchParams): UndoLink | null {
  const [id, at, expires, signature] = ['id', 'at', 'expires', 'signature'].map((key) => params.get(key))

  return id && at && expires && signature ? { id, at, expires, signature } : null
}
