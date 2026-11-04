import { useState } from 'react'
import { Link, useSearchParams } from 'react-router'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import { AuthCard } from '@/features/auth/AuthCard'
import { ResetPasswordForm } from '@/features/auth/ResetPasswordForm'
import { TwoFactorChallengeForm } from '@/features/auth/TwoFactorChallengeForm'

/** After the new password is saved with two-factor on: the code step, or it ended without one. */
type Step = { name: 'code'; challenge: string } | { name: 'ended'; notice: string }

/** The link from the reset email: /reset-password?token=…&email=… */
export function ResetPasswordPage() {
  // Read on every render, never copied into state: the link's values are the source.
  const [searchParams] = useSearchParams()
  const token = searchParams.get('token') ?? ''
  const email = searchParams.get('email') ?? ''
  const [step, setStep] = useState<Step | null>(null)

  if (step?.name === 'code') {
    return (
      <>
        <title>Two-factor authentication · React SaaS</title>
        <AuthCard title="Two-factor authentication" description="Your new password is saved. Enter a code to log in.">
          <TwoFactorChallengeForm
            challenge={step.challenge}
            onExpired={(notice) => setStep({ name: 'ended', notice })}
            onBack={() => setStep({ name: 'ended', notice: 'Log in with your new password.' })}
          />
        </AuthCard>
      </>
    )
  }

  return (
    <>
      <title>Set a new password · React SaaS</title>
      <AuthCard title="Set a new password" description="Choose a password you don't use anywhere else.">
        {step?.name === 'ended' ? (
          // The password changed already; only the login is left, from the login page.
          <Alert>
            <AlertTitle>Your new password is saved</AlertTitle>
            <AlertDescription>
              <p>{step.notice}</p>
              <Link to="/login" className="font-medium underline underline-offset-4">
                Go to log in
              </Link>
            </AlertDescription>
          </Alert>
        ) : token && email ? (
          <ResetPasswordForm token={token} email={email} onChallenge={(challenge) => setStep({ name: 'code', challenge })} />
        ) : (
          <Alert variant="destructive">
            <AlertTitle>This link is incomplete</AlertTitle>
            <AlertDescription>
              Open the link from the email again, or{' '}
              <Link to="/forgot-password" className="font-medium underline underline-offset-4">
                ask for a new one
              </Link>
              .
            </AlertDescription>
          </Alert>
        )}
      </AuthCard>
    </>
  )
}
