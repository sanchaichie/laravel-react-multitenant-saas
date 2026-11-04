import { useEffect, useRef } from 'react'
import { CircleCheckIcon } from 'lucide-react'
import { Link, useSearchParams } from 'react-router'
import { ErrorState } from '@/components/ErrorState'
import { Button } from '@/components/ui/button'
import { Spinner } from '@/components/ui/spinner'
import { type EmailChangeLink, useConfirmEmailChange } from '@/features/account/api'
import { AuthCard } from '@/features/auth/AuthCard'
import { errorMessage } from '@/lib/apiErrors'

/**
 * The link from the "confirm your new email" email: posts it to the API once, on
 * arrival, like /verify-email. Logged out, RequireAuth sends you through login
 * (with the current email) and back here first.
 */
export function ConfirmEmailPage() {
  const [searchParams] = useSearchParams()
  const link = readLink(searchParams)
  const confirm = useConfirmEmailChange()

  // Once, even when StrictMode mounts the page twice (see VerifyEmailPage).
  const posted = useRef(false)
  const { mutate } = confirm
  useEffect(() => {
    if (link && !posted.current) {
      posted.current = true
      mutate(link)
    }
  }, [link, mutate])

  const backToAccount = (
    <Button asChild>
      <Link to="/account">Go to Account settings</Link>
    </Button>
  )

  if (!link) {
    return (
      <AuthCard title="Confirm your new email">
        <title>Confirm your new email · React SaaS</title>
        <ErrorState title="This link is incomplete" description="Open the link from the email again.">
          {backToAccount}
        </ErrorState>
      </AuthCard>
    )
  }

  if (confirm.isError) {
    return (
      <AuthCard title="Confirm your new email">
        <title>Confirm your new email · React SaaS</title>
        <ErrorState
          title="This link doesn't work"
          description={`${errorMessage(confirm.error)} Links expire after an hour, work only for the account that asked, and only for its latest request.`}
        >
          {backToAccount}
        </ErrorState>
      </AuthCard>
    )
  }

  if (confirm.isSuccess) {
    return (
      <AuthCard title="Email changed">
        <title>Email changed · React SaaS</title>
        <div className="grid justify-items-center gap-4 py-2 text-center">
          <CircleCheckIcon className="size-8 text-muted-foreground" aria-hidden />
          <p className="text-sm text-muted-foreground">
            Your account's email is now <span className="font-medium break-all text-foreground">{confirm.data.data.email}</span>.
            Your other devices were signed out.
          </p>
          {backToAccount}
        </div>
      </AuthCard>
    )
  }

  return (
    <AuthCard title="Confirm your new email">
      <div className="flex justify-center py-6">
        <Spinner className="size-6 text-muted-foreground" />
      </div>
    </AuthCard>
  )
}

function readLink(params: URLSearchParams): EmailChangeLink | null {
  const id = params.get('id')
  const hash = params.get('hash')
  const expires = params.get('expires')
  const signature = params.get('signature')

  return id && hash && expires && signature ? { id, hash, expires, signature } : null
}
