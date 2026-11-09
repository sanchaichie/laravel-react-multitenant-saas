'use client'

import { useTransition } from 'react'
import { toast } from 'sonner'
import { Alert, AlertAction, AlertDescription, AlertTitle } from '@/components/ui/alert'
import { Button } from '@/components/ui/button'
import { Spinner } from '@/components/ui/spinner'
import { resendVerification } from '@/features/auth/actions'
import { MailIcon } from '@/icons'

/** Shown on logged-in pages until the email is verified (inviting people and AI need it). */
export function VerifyEmailBanner({ email }: { email: string }) {
  const [isPending, startTransition] = useTransition()

  function onResend() {
    startTransition(async () => {
      const result = await resendVerification()
      if (result.ok) {
        toast.success(result.message)
      } else {
        toast.error('Could not send a new link', { description: result.message })
      }
    })
  }

  return (
    <Alert variant="info" className="mb-6">
      <MailIcon aria-hidden />
      <AlertTitle>Verify your email</AlertTitle>
      <AlertDescription>We sent a link to {email}. Verify it to invite people and use AI features.</AlertDescription>
      <AlertAction>
        <Button variant="outline" size="sm" onClick={onResend} disabled={isPending}>
          {isPending && <Spinner />}
          Resend
        </Button>
      </AlertAction>
    </Alert>
  )
}
