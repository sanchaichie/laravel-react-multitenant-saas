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
  old: string
  new: string
  expires: string
  signature: string
}

/**
 * The "undo" link from the "your email was changed" email. No login needed: whoever
 * follows it may have been locked out. Every device is signed out, this one too.
 */
export function RevertEmailPage() {
  const [searchParams] = useSearchParams()
  const link = readLink(searchParams)
  const clearSession = useAuth((s) => s.clearSession)
  const revert = useMutation({
    mutationFn: ({ id, old, new: next, expires, signature }: UndoLink) =>
      apiFetch<{ message: string }>(
        `/api/account/email/revert/${id}/${old}/${next}?${new URLSearchParams({ expires, signature })}`,
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

  const toLogin = (
    <Button asChild>
      <Link to="/forgot-password">Ask for a password reset link</Link>
    </Button>
  )

  if (!link || revert.isError) {
    return (
      <AuthCard title="Undo the email change">
        <title>Undo the email change · React SaaS</title>
        <ErrorState
          title="This link doesn't work"
          description={
            link
              ? `${errorMessage(revert.error)} Undo links work for 7 days, once.`
              : 'Open the link from the email again.'
          }
        >
          {toLogin}
        </ErrorState>
      </AuthCard>
    )
  }

  if (revert.isSuccess) {
    return (
      <AuthCard title="Email change undone">
        <title>Email change undone · React SaaS</title>
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
    <AuthCard title="Undo the email change">
      <div className="flex justify-center py-6">
        <Spinner className="size-6 text-muted-foreground" />
      </div>
    </AuthCard>
  )
}

function readLink(params: URLSearchParams): UndoLink | null {
  const [id, old, next, expires, signature] = ['id', 'old', 'new', 'expires', 'signature'].map((key) => params.get(key))

  return id && old && next && expires && signature ? { id, old, new: next, expires, signature } : null
}
