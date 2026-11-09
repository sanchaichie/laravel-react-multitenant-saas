'use client'

import { useActionState, useState } from 'react'
import Link from 'next/link'
import { FormMessage } from '@/components/FormMessage'
import { TextField } from '@/components/TextField'
import { Button } from '@/components/ui/button'
import { Spinner } from '@/components/ui/spinner'
import { resetPassword } from './actions'
import type { AuthFormState } from './state'
import { TwoFactorForm } from './TwoFactorForm'

/** The link's token and email go back with the new password, as hidden fields. */
export function ResetPasswordForm({ token, email }: { token: string; email: string }) {
  const [state, formAction, isPending] = useActionState<AuthFormState, FormData>(resetPassword, {})
  const [ended, setEnded] = useState<string | null>(null)

  if (ended !== null) {
    // The password changed already; only the login is left.
    return (
      <div className="space-y-4">
        <FormMessage variant="success" message={`Your new password is saved. ${ended}`} />
        <Link href="/login" className="text-sm font-medium text-brand-500 hover:text-brand-600 dark:text-brand-400">
          Go to log in
        </Link>
      </div>
    )
  }

  if (state.challenge) {
    return (
      <TwoFactorForm
        challenge={state.challenge}
        email={email}
        redirectTo="/"
        onBack={(message) => setEnded(message ?? 'Log in with your new password.')}
      />
    )
  }

  return (
    <form action={formAction} className="space-y-5" noValidate>
      <input type="hidden" name="token" value={token} />
      <input type="hidden" name="email" value={email} />
      <FormMessage message={state.message} />
      <TextField
        name="password"
        label="New password"
        type="password"
        autoComplete="new-password"
        hint="At least 8 characters."
        error={state.errors?.password}
      />
      <TextField
        name="password_confirmation"
        label="Repeat password"
        type="password"
        autoComplete="new-password"
        error={state.errors?.password_confirmation}
      />
      <Button type="submit" size="lg" className="w-full" disabled={isPending}>
        {isPending && <Spinner />}
        Save and log in
      </Button>
    </form>
  )
}
