'use client'

import { useEffect, useRef, useState, useTransition } from 'react'
import Link from 'next/link'
import { toast } from 'sonner'
import { FormMessage } from '@/components/FormMessage'
import { Button, buttonVariants } from '@/components/ui/button'
import { Spinner } from '@/components/ui/spinner'
import { resendVerification, verifyEmail } from './actions'

type Link4 = { id: string; hash: string; expires: string; signature: string }
type Status = { name: 'verifying' } | { name: 'verified' } | { name: 'failed'; message: string }

/**
 * Posts the link back once, after the page shows (a GET page shouldn't change
 * anything on the server while rendering). The ref keeps StrictMode's second
 * mount in development from posting it twice.
 */
export function VerifyEmail({ link }: { link: Link4 | null }) {
  const [status, setStatus] = useState<Status>(
    link ? { name: 'verifying' } : { name: 'failed', message: 'This link is incomplete. Open the link from the email again.' },
  )
  const posted = useRef(false)
  const [resending, startResend] = useTransition()

  useEffect(() => {
    if (!link || posted.current) {
      return
    }
    posted.current = true
    verifyEmail(link)
      .then((result) =>
        setStatus(
          result.ok
            ? { name: 'verified' }
            : { name: 'failed', message: `${result.message} Links expire after an hour and only work for the account they were sent to.` },
        ),
      )
      .catch(() => setStatus({ name: 'failed', message: 'Cannot reach the server. Try again in a moment.' }))
  }, [link])

  function onResend() {
    startResend(async () => {
      const result = await resendVerification()
      if (result.ok) {
        toast.success(result.message)
      } else {
        toast.error('Could not send a new link', { description: result.message })
      }
    })
  }

  if (status.name === 'verifying') {
    return (
      <div className="flex justify-center py-6" aria-label="Verifying">
        <Spinner className="size-6 text-gray-400" />
      </div>
    )
  }

  if (status.name === 'verified') {
    return (
      <div className="space-y-4">
        <FormMessage variant="success" message="Thanks, your email is verified." />
        <Link href="/" className={buttonVariants()}>
          Go to the overview
        </Link>
      </div>
    )
  }

  return (
    <div className="space-y-4">
      <FormMessage message={status.message} />
      <Button onClick={onResend} disabled={resending}>
        {resending && <Spinner />}
        Send a new link
      </Button>
    </div>
  )
}
