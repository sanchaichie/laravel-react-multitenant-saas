import { useState } from 'react'
import { Link, useSearchParams } from 'react-router'
import { AuthCard } from '@/features/auth/AuthCard'
import { LoginForm } from '@/features/auth/LoginForm'
import { TwoFactorChallengeForm } from '@/features/auth/TwoFactorChallengeForm'

/** Where the login stands: the password step (maybe after an expired code step), or the code step. */
type Step = { name: 'password'; email: string; notice?: string } | { name: 'code'; email: string; challenge: string }

export function LoginPage() {
  // Keep ?redirect= when switching to the register page.
  const [searchParams] = useSearchParams()
  const query = searchParams.toString()
  // The step lives here, above both forms ("lifting state up"): the card's title
  // changes with it, and the email survives going back. Only in memory: a reload
  // starts over, and the challenge is never stored.
  const [step, setStep] = useState<Step>({ name: 'password', email: '' })

  if (step.name === 'code') {
    return (
      <>
        <title>Two-factor authentication · React SaaS</title>
        <AuthCard title="Two-factor authentication" description={`Logging in as ${step.email}.`}>
          <TwoFactorChallengeForm
            challenge={step.challenge}
            onExpired={(notice) => setStep({ name: 'password', email: step.email, notice })}
            onBack={() => setStep({ name: 'password', email: step.email })}
          />
        </AuthCard>
      </>
    )
  }

  return (
    <>
      {/* React 19 moves <title> into <head>, like useSeoMeta. */}
      <title>Log in · React SaaS</title>
      <AuthCard
        title="Log in"
        description="Welcome back."
        footer={
          <p>
            No account yet?{' '}
            <Link to={`/register${query ? `?${query}` : ''}`} className="font-medium text-primary underline-offset-4 hover:underline">
              Create one
            </Link>
          </p>
        }
      >
        <LoginForm
          defaultEmail={step.email}
          notice={step.notice}
          onChallenge={(challenge, email) => setStep({ name: 'code', email, challenge })}
        />
      </AuthCard>
    </>
  )
}
