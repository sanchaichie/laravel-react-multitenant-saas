'use client'

import { useActionState } from 'react'
import { FormMessage } from '@/components/FormMessage'
import { TextField } from '@/components/TextField'
import { Button } from '@/components/ui/button'
import { Spinner } from '@/components/ui/spinner'
import { forgotPassword } from './actions'
import type { FormState } from './state'

export function ForgotPasswordForm() {
  const [state, formAction, isPending] = useActionState<FormState, FormData>(forgotPassword, {})

  if (state.done) {
    return <FormMessage message={state.message} variant="success" />
  }

  return (
    <form action={formAction} className="space-y-5" noValidate>
      <FormMessage message={state.message} />
      <TextField
        key={state.values?.email ?? ''}
        name="email"
        label="Email"
        type="email"
        autoComplete="email"
        defaultValue={state.values?.email}
        error={state.errors?.email}
      />
      <Button type="submit" size="lg" className="w-full" disabled={isPending}>
        {isPending && <Spinner />}
        Email me a reset link
      </Button>
    </form>
  )
}
