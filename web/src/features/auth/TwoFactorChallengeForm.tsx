import { useState } from 'react'
import { zodResolver } from '@hookform/resolvers/zod'
import { Controller, useForm } from 'react-hook-form'
import { toast } from 'sonner'
import { z } from 'zod'
import { CodeInput } from '@/components/CodeInput'
import { Button } from '@/components/ui/button'
import { Field, FieldDescription, FieldError, FieldGroup, FieldLabel } from '@/components/ui/field'
import { Input } from '@/components/ui/input'
import { Spinner } from '@/components/ui/spinner'
import { ApiError } from '@/lib/api'
import { applyFieldErrors, errorMessage } from '@/lib/apiErrors'
import { describedBy } from '@/lib/aria'
import { useCompleteTwoFactor } from './api'

interface TwoFactorChallengeFormProps {
  /** From the login or reset answer; kept in the page's state, never stored. */
  challenge: string
  /** The challenge is gone (5 minutes, 5 wrong tries, or the account is paused): start over. */
  onExpired: (message: string) => void
  onBack: () => void
}

/**
 * The second step of logging in with two-factor on: a code from the app, or a
 * recovery code. On success the session is stored and RequireGuest moves on.
 */
export function TwoFactorChallengeForm({ challenge, onExpired, onBack }: TwoFactorChallengeFormProps) {
  const [useRecoveryCode, setUseRecoveryCode] = useState(false)

  return (
    <div className="grid gap-4">
      {/* One or the other: switching unmounts the old form, so each starts empty. */}
      {useRecoveryCode ? (
        <RecoveryCodeForm challenge={challenge} onExpired={onExpired} />
      ) : (
        <AppCodeForm challenge={challenge} onExpired={onExpired} />
      )}
      <div className="flex flex-wrap items-center justify-between gap-2">
        <Button type="button" variant="link" className="h-auto px-0" onClick={() => setUseRecoveryCode((value) => !value)}>
          {useRecoveryCode ? 'Use the authenticator app instead' : 'Use a recovery code instead'}
        </Button>
        <Button type="button" variant="ghost" size="sm" onClick={onBack}>
          Back
        </Button>
      </div>
    </div>
  )
}

/** The challenge's own error (422 on `challenge`), meaning this login is over. */
function expiredMessage(error: unknown): string | undefined {
  return error instanceof ApiError && error.status === 422 ? error.data?.errors?.challenge?.[0] : undefined
}

const appCodeSchema = z.object({ code: z.string().regex(/^\d{6}$/, 'Enter the 6 digits from the app.') })

function AppCodeForm({ challenge, onExpired }: Pick<TwoFactorChallengeFormProps, 'challenge' | 'onExpired'>) {
  const complete = useCompleteTwoFactor()
  const form = useForm<z.infer<typeof appCodeSchema>>({
    resolver: zodResolver(appCodeSchema),
    defaultValues: { code: '' },
  })
  const { errors } = form.formState

  function onSubmit({ code }: z.infer<typeof appCodeSchema>) {
    complete.mutate(
      { challenge, code },
      {
        onError: (error) => {
          const expired = expiredMessage(error)
          if (expired) {
            onExpired(expired)
            return
          }
          // Empty the boxes for the next try; the error stays under them.
          form.setValue('code', '')
          if (!applyFieldErrors(error, form.setError, ['code'])) {
            toast.error('Could not log in', { description: errorMessage(error) })
          }
        },
      },
    )
  }

  const submit = form.handleSubmit(onSubmit)

  return (
    <form onSubmit={submit} noValidate>
      <FieldGroup>
        <Field data-invalid={!!errors.code}>
          <FieldLabel htmlFor="two-factor-code">Code from your authenticator app</FieldLabel>
          <FieldDescription id="two-factor-code-hint">
            Open the app on your phone and enter the 6-digit code for this account.
          </FieldDescription>
          {/* Not a native input, so Controller wires it to the form (register() can't). */}
          <Controller
            control={form.control}
            name="code"
            render={({ field }) => (
              <CodeInput
                id="two-factor-code"
                ref={field.ref}
              value={field.value}
                onChange={field.onChange}
                onBlur={field.onBlur}
                // All six typed or pasted: send it, no button press needed.
                onComplete={() => {
                  if (!complete.isPending) {
                    void submit()
                  }
                }}
                invalid={!!errors.code}
                aria-describedby={describedBy('two-factor-code-hint', errors.code && 'two-factor-code-error')}
                autoFocus
              />
            )}
          />
          <FieldError id="two-factor-code-error" errors={[errors.code]} />
        </Field>
        <Button type="submit" disabled={complete.isPending}>
          {complete.isPending && <Spinner />}
          Verify
        </Button>
      </FieldGroup>
    </form>
  )
}

const recoveryCodeSchema = z.object({ recovery_code: z.string().trim().min(1, 'Enter one of your recovery codes.') })

function RecoveryCodeForm({ challenge, onExpired }: Pick<TwoFactorChallengeFormProps, 'challenge' | 'onExpired'>) {
  const complete = useCompleteTwoFactor()
  const form = useForm<z.infer<typeof recoveryCodeSchema>>({
    resolver: zodResolver(recoveryCodeSchema),
    defaultValues: { recovery_code: '' },
  })
  const { errors } = form.formState

  function onSubmit({ recovery_code }: z.infer<typeof recoveryCodeSchema>) {
    complete.mutate(
      { challenge, recovery_code },
      {
        onError: (error) => {
          const expired = expiredMessage(error)
          if (expired) {
            onExpired(expired)
          } else if (!applyFieldErrors(error, form.setError, ['recovery_code'])) {
            toast.error('Could not log in', { description: errorMessage(error) })
          }
        },
      },
    )
  }

  return (
    <form onSubmit={form.handleSubmit(onSubmit)} noValidate>
      <FieldGroup>
        <Field data-invalid={!!errors.recovery_code}>
          <FieldLabel htmlFor="two-factor-recovery-code">Recovery code</FieldLabel>
          <FieldDescription id="two-factor-recovery-code-hint">
            One of the codes you saved when you turned on two-factor, like a1b2c-d3e4f. Each works once.
          </FieldDescription>
          <Input
            id="two-factor-recovery-code"
            autoComplete="off"
            autoCapitalize="none"
            spellCheck={false}
            autoFocus
            aria-invalid={!!errors.recovery_code}
            aria-describedby={describedBy(
              'two-factor-recovery-code-hint',
              errors.recovery_code && 'two-factor-recovery-code-error',
            )}
            {...form.register('recovery_code')}
          />
          <FieldError id="two-factor-recovery-code-error" errors={[errors.recovery_code]} />
        </Field>
        <Button type="submit" disabled={complete.isPending}>
          {complete.isPending && <Spinner />}
          Verify
        </Button>
      </FieldGroup>
    </form>
  )
}
