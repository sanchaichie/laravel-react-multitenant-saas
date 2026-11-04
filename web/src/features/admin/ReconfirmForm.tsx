import { useId } from 'react'
import { zodResolver } from '@hookform/resolvers/zod'
import { Controller, useForm } from 'react-hook-form'
import { z } from 'zod'
import { CodeInput } from '@/components/CodeInput'
import { Button } from '@/components/ui/button'
import { Field, FieldDescription, FieldError, FieldGroup, FieldLabel } from '@/components/ui/field'
import { Spinner } from '@/components/ui/spinner'
import { applyFieldErrors, errorMessage } from '@/lib/apiErrors'
import { describedBy } from '@/lib/aria'
import { useReconfirmTwoFactor } from './api'

const schema = z.object({ code: z.string().regex(/^\d{6}$/, 'Enter the 6 digits from the app.') })

interface ReconfirmFormProps {
  /** After the code was accepted (admin queries are already refetching). */
  onConfirmed?: () => void
  submitLabel?: string
  /** Refetch the admin queries after (the gate); an action dialog resends its action instead. */
  refetch?: boolean
}

/**
 * A code from the authenticator app for this device, when an admin route says the
 * last one is too old (two_factor_reauth). Wrong codes count toward the account's
 * limit, like at login; no recovery codes here.
 */
export function ReconfirmForm({ onConfirmed, submitLabel = 'Confirm', refetch = true }: ReconfirmFormProps) {
  const id = useId()
  const reconfirm = useReconfirmTwoFactor({ refetch })
  const form = useForm<z.infer<typeof schema>>({ resolver: zodResolver(schema), defaultValues: { code: '' } })
  const { errors } = form.formState

  function onSubmit({ code }: z.infer<typeof schema>) {
    reconfirm.mutate(code, {
      onSuccess: () => onConfirmed?.(),
      onError: (error) => {
        form.setValue('code', '')
        // A wrong code, or the account paused after too many: under the boxes either way.
        if (!applyFieldErrors(error, form.setError, ['code'])) {
          form.setError('code', { type: 'server', message: errorMessage(error) }, { shouldFocus: true })
        }
      },
    })
  }

  const submit = form.handleSubmit(onSubmit)
  const hintId = `${id}-hint`
  const errorId = `${id}-error`

  return (
    <form onSubmit={submit} noValidate>
      <FieldGroup>
        <Field data-invalid={!!errors.code}>
          <FieldLabel htmlFor={id}>Code from your authenticator app</FieldLabel>
          <FieldDescription id={hintId}>The admin area asks for a fresh code now and then.</FieldDescription>
          <Controller
            control={form.control}
            name="code"
            render={({ field }) => (
              <CodeInput
                id={id}
                ref={field.ref}
                value={field.value}
                onChange={field.onChange}
                onBlur={field.onBlur}
                onComplete={() => {
                  if (!reconfirm.isPending) {
                    void submit()
                  }
                }}
                invalid={!!errors.code}
                aria-describedby={describedBy(hintId, errors.code && errorId)}
                autoFocus
              />
            )}
          />
          <FieldError id={errorId} errors={[errors.code]} />
        </Field>
        <Button type="submit" disabled={reconfirm.isPending}>
          {reconfirm.isPending && <Spinner />}
          {submitLabel}
        </Button>
      </FieldGroup>
    </form>
  )
}
