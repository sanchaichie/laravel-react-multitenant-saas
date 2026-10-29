import { useEffect, useRef } from 'react'
import { CircleCheckIcon } from 'lucide-react'
import { Link, useSearchParams } from 'react-router'
import { toast } from 'sonner'
import { ErrorState } from '@/components/ErrorState'
import { Button } from '@/components/ui/button'
import { Spinner } from '@/components/ui/spinner'
import { AuthCard } from '@/features/auth/AuthCard'
import { useResendVerification, useVerifyEmail, type VerificationLink } from '@/features/auth/api'
import { errorMessage } from '@/lib/apiErrors'

/**
 * The link from the verification email: posts it to the API once, on arrival.
 * Logged out, RequireAuth sends you through login and back here first.
 */
export function VerifyEmailPage() {
  const [searchParams] = useSearchParams()
  const link = readLink(searchParams)
  const verify = useVerifyEmail()
  const resend = useResendVerification()

  // An effect runs after the page is shown: the right place for a side effect
  // like this request. In development StrictMode mounts the page twice to catch
  // effects that aren't safe to repeat; the ref makes sure we post only once
  // (Vue's onMounted runs once, so it needs no such guard).
  const posted = useRef(false)
  const { mutate } = verify
  useEffect(() => {
    if (link && !posted.current) {
      posted.current = true
      mutate(link)
    }
  }, [link, mutate])

  function onResend() {
    resend.mutate(undefined, {
      onSuccess: (data) => toast.success(data.message),
      onError: (error) => toast.error('Could not send a new link', { description: errorMessage(error) }),
    })
  }

  if (!link) {
    return (
      <AuthCard title="Verify your email">
        <ErrorState title="This link is incomplete" description="Open the link from the email again, or send a new one.">
          <Button onClick={onResend} disabled={resend.isPending}>
            Send a new link
          </Button>
        </ErrorState>
      </AuthCard>
    )
  }

  if (verify.isError) {
    return (
      <AuthCard title="Verify your email">
        <title>Verify your email · React SaaS</title>
        <ErrorState
          title="This link doesn't work"
          description={`${errorMessage(verify.error)} Links expire after an hour and only work for the account they were sent to.`}
        >
          <Button onClick={onResend} disabled={resend.isPending}>
            {resend.isPending && <Spinner />}
            Send a new link
          </Button>
        </ErrorState>
      </AuthCard>
    )
  }

  if (verify.isSuccess) {
    return (
      <AuthCard title="Email verified">
        <title>Email verified · React SaaS</title>
        <div className="grid justify-items-center gap-4 py-2 text-center">
          <CircleCheckIcon className="size-8 text-muted-foreground" aria-hidden />
          <p className="text-sm text-muted-foreground">Thanks. You can now invite people to your organizations.</p>
          <Button asChild>
            <Link to="/">Go to the dashboard</Link>
          </Button>
        </div>
      </AuthCard>
    )
  }

  return (
    <AuthCard title="Verify your email">
      <div className="flex justify-center py-6">
        <Spinner className="size-6 text-muted-foreground" />
      </div>
    </AuthCard>
  )
}

function readLink(params: URLSearchParams): VerificationLink | null {
  const id = params.get('id')
  const hash = params.get('hash')
  const expires = params.get('expires')
  const signature = params.get('signature')

  return id && hash && expires && signature ? { id, hash, expires, signature } : null
}
