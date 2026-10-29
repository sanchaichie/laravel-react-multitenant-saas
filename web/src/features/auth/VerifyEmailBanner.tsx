import { MailIcon } from 'lucide-react'
import { toast } from 'sonner'
import { Alert, AlertAction, AlertDescription, AlertTitle } from '@/components/ui/alert'
import { Button } from '@/components/ui/button'
import { Spinner } from '@/components/ui/spinner'
import { errorMessage } from '@/lib/apiErrors'
import { useAuth } from '@/stores/auth'
import { useResendVerification } from './api'

/** Shown on logged-in pages until the email is verified (inviting people needs it). */
export function VerifyEmailBanner() {
  const user = useAuth((s) => s.user)
  const resend = useResendVerification()

  if (!user || user.email_verified) {
    return null
  }

  function onResend() {
    resend.mutate(undefined, {
      onSuccess: (data) => toast.success(data.message),
      onError: (error) => toast.error('Could not send a new link', { description: errorMessage(error) }),
    })
  }

  return (
    <Alert className="mb-6">
      <MailIcon aria-hidden />
      <AlertTitle>Verify your email</AlertTitle>
      <AlertDescription>
        We sent a link to {user.email}. Verify it to invite people to your organizations.
      </AlertDescription>
      <AlertAction>
        <Button variant="outline" size="sm" onClick={onResend} disabled={resend.isPending}>
          {resend.isPending && <Spinner />}
          Resend
        </Button>
      </AlertAction>
    </Alert>
  )
}
