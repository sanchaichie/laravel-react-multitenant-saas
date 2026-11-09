'use client'

import { useActionState, useState } from 'react'
import Link from 'next/link'
import { FormMessage } from '@/components/FormMessage'
import { TextField } from '@/components/TextField'
import { Button } from '@/components/ui/button'
import { Spinner } from '@/components/ui/spinner'
import { login } from './actions'
import type { AuthFormState } from './state'
import { TwoFactorForm } from './TwoFactorForm'

/**
 * useActionState ↔ web's useMutation: it calls the Server Action with the form's
 * data and gives back what the action returned (errors, or a two-factor challenge)
 * plus isPending. The form also works before the JavaScript has loaded: it posts
 * like a classic HTML form (progressive enhancement).
 */
export function LoginForm({ redirectTo }: { redirectTo: string }) {
  const [state, formAction, isPending] = useActionState<AuthFormState, FormData>(login, {})
  // Back from the code step: remember which challenge was left, so the password step shows again.
  const [leftChallenge, setLeftChallenge] = useState<string | null>(null)
  const [notice, setNotice] = useState<string | null>(null)

  if (state.challenge && state.challenge !== leftChallenge) {
    return (
      <TwoFactorForm
        challenge={state.challenge}
        email={state.values?.email ?? ''}
        redirectTo={redirectTo}
        onBack={(message) => {
          setLeftChallenge(state.challenge ?? null)
          setNotice(message ?? null)
        }}
      />
    )
  }

  return (
    <form
      action={(formData) => {
        // The "login expired" notice is about the previous try.
        setNotice(null)
        formAction(formData)
      }}
      className="space-y-5"
      noValidate
    >
      <input type="hidden" name="redirect" value={redirectTo} />
      <FormMessage message={notice ?? undefined} variant="info" />
      <FormMessage message={state.message} />
      {/* key: a new answer remounts the input, so defaultValue (the typed email) applies again. */}
      <TextField
        key={`email-${state.values?.email ?? ''}`}
        name="email"
        label="Email"
        type="email"
        autoComplete="email"
        defaultValue={state.values?.email}
        error={state.errors?.email}
      />
      <TextField
        name="password"
        label="Password"
        type="password"
        autoComplete="current-password"
        error={state.errors?.password}
        labelAside={
          <Link href="/forgot-password" className="text-sm text-brand-500 hover:text-brand-600 dark:text-brand-400">
            Forgot password?
          </Link>
        }
      />
      <Button type="submit" size="lg" className="w-full" disabled={isPending}>
        {isPending && <Spinner />}
        Log in
      </Button>
    </form>
  )
}
