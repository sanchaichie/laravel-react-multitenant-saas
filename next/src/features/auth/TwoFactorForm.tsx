'use client'

import { useActionState, useRef, useState } from 'react'
import { FormMessage } from '@/components/FormMessage'
import { TextField } from '@/components/TextField'
import { Button } from '@/components/ui/button'
import { Field, FieldDescription, FieldError, FieldLabel } from '@/components/ui/field'
import { InputOTP, InputOTPGroup, InputOTPSlot } from '@/components/ui/input-otp'
import { Spinner } from '@/components/ui/spinner'
import { completeTwoFactor } from './actions'
import type { AuthFormState } from './state'

interface TwoFactorFormProps {
  /** From the login or reset answer: kept in this page's memory only, never stored. */
  challenge: string
  email?: string
  redirectTo: string
  /** Back to the password step; `message` says why when the challenge expired. */
  onBack: (message?: string) => void
}

/** The second step of logging in with two-factor on: a code from the app, or a recovery code. */
export function TwoFactorForm({ challenge, email, redirectTo, onBack }: TwoFactorFormProps) {
  const [state, formAction, isPending] = useActionState<AuthFormState, FormData>(completeTwoFactor, { challenge })
  const [useRecoveryCode, setUseRecoveryCode] = useState(false)
  const formRef = useRef<HTMLFormElement>(null)

  // The action answered without a challenge: it expired, this login is over.
  if (!state.challenge) {
    return (
      <div className="space-y-5">
        <FormMessage message={state.message ?? 'This login expired.'} />
        <Button type="button" className="w-full" onClick={() => onBack(state.message)}>
          Log in again
        </Button>
      </div>
    )
  }

  return (
    <div className="space-y-5">
      <div>
        <h2 className="text-lg font-semibold text-gray-800 dark:text-white/90">Two-factor authentication</h2>
        {email && <p className="text-sm text-gray-500 dark:text-gray-400">Logging in as {email}.</p>}
      </div>
      <form ref={formRef} action={formAction} className="space-y-5" noValidate>
        <input type="hidden" name="challenge" value={state.challenge} />
        <input type="hidden" name="redirect" value={redirectTo} />
        <FormMessage message={state.message} />
        {useRecoveryCode ? (
          <TextField
            name="recovery_code"
            label="Recovery code"
            hint="One of the codes you saved when you turned on two-factor, like a1b2c-d3e4f. Each works once."
            autoComplete="off"
            autoCapitalize="none"
            spellCheck={false}
            autoFocus
            error={state.errors?.recovery_code}
          />
        ) : (
          <Field data-invalid={Boolean(state.errors?.code)} controlId="two-factor-code">
            <FieldLabel>Code from your authenticator app</FieldLabel>
            <FieldDescription id="two-factor-code-hint">
              Open the app on your phone and enter the 6-digit code for this account.
            </FieldDescription>
            {/* key: each failed try remounts it empty (input-otp keeps its own value). */}
            <InputOTP
              key={state.attempt ?? 0}
              name="code"
              maxLength={6}
              inputMode="numeric"
              autoComplete="one-time-code"
              autoFocus
              aria-describedby={state.errors?.code ? 'two-factor-code-hint two-factor-code-error' : 'two-factor-code-hint'}
              // All six typed or pasted: send it, no button press needed.
              onComplete={() => formRef.current?.requestSubmit()}
            >
              <InputOTPGroup>
                {[0, 1, 2, 3, 4, 5].map((index) => (
                  <InputOTPSlot key={index} index={index} />
                ))}
              </InputOTPGroup>
            </InputOTP>
            {state.errors?.code && <FieldError id="two-factor-code-error">{state.errors.code}</FieldError>}
          </Field>
        )}
        <Button type="submit" size="lg" className="w-full" disabled={isPending}>
          {isPending && <Spinner />}
          Verify
        </Button>
      </form>
      <div className="flex flex-wrap items-center justify-between gap-2">
        <Button type="button" variant="link" onClick={() => setUseRecoveryCode((value) => !value)}>
          {useRecoveryCode ? 'Use the authenticator app instead' : 'Use a recovery code instead'}
        </Button>
        <Button type="button" variant="ghost" size="sm" onClick={() => onBack()}>
          Back
        </Button>
      </div>
    </div>
  )
}
