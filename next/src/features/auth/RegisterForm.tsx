'use client'

import { useActionState } from 'react'
import { FormMessage } from '@/components/FormMessage'
import { TextField } from '@/components/TextField'
import { Button } from '@/components/ui/button'
import { Spinner } from '@/components/ui/spinner'
import { register } from './actions'
import type { FormState } from './state'

export function RegisterForm() {
  const [state, formAction, isPending] = useActionState<FormState, FormData>(register, {})
  const values = state.values ?? {}

  return (
    // key on the inputs: each answer remounts them with what was typed (React resets a form after its action).
    <form action={formAction} className="space-y-5" noValidate key={JSON.stringify(values)}>
      <FormMessage message={state.message} />
      <TextField name="name" label="Name" autoComplete="name" defaultValue={values.name} error={state.errors?.name} />
      <TextField
        name="email"
        label="Email"
        type="email"
        autoComplete="email"
        defaultValue={values.email}
        error={state.errors?.email}
      />
      <TextField
        name="password"
        label="Password"
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
        Create account
      </Button>
    </form>
  )
}
