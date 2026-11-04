import { zodResolver } from '@hookform/resolvers/zod'
import { useForm } from 'react-hook-form'
import { Link } from 'react-router'
import { toast } from 'sonner'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import { Button } from '@/components/ui/button'
import { Field, FieldError, FieldGroup, FieldLabel } from '@/components/ui/field'
import { Input } from '@/components/ui/input'
import { Spinner } from '@/components/ui/spinner'
import { ApiError } from '@/lib/api'
import { applyFieldErrors, errorMessage } from '@/lib/apiErrors'
import { describedBy } from '@/lib/aria'
import { useResetPassword } from './api'
import { resetPasswordSchema, type ResetPasswordValues } from './schemas'

interface ResetPasswordFormProps {
  /** From the emailed link (the page reads them from the URL). */
  token: string
  email: string
  /** Two-factor is on: the password is changed, and the page asks for a code. */
  onChallenge: (challenge: string) => void
}

/** A new password for the account in the link; on success the user is logged in. */
export function ResetPasswordForm({ token, email, onChallenge }: ResetPasswordFormProps) {
  const reset = useResetPassword()
  const form = useForm<ResetPasswordValues>({
    resolver: zodResolver(resetPasswordSchema),
    defaultValues: { password: '', password_confirmation: '' },
  })
  const { errors } = form.formState

  // The API reports a bad or expired link on `email`, which isn't a field here.
  const linkError =
    reset.error instanceof ApiError && reset.error.status === 422 ? reset.error.data?.errors?.email?.[0] : undefined

  function onSubmit(values: ResetPasswordValues) {
    reset.mutate(
      { token, email, ...values },
      {
        onSuccess: (result) => {
          if ('challenge' in result) {
            onChallenge(result.challenge)
          }
        },
        onError: (error) => {
          const handled = error instanceof ApiError && error.status === 422 && error.data?.errors?.email
          if (!handled && !applyFieldErrors(error, form.setError, ['password', 'password_confirmation'])) {
            toast.error('Could not reset the password', { description: errorMessage(error) })
          }
        },
      },
    )
  }

  if (linkError) {
    return (
      <Alert variant="destructive">
        <AlertTitle>{linkError}</AlertTitle>
        <AlertDescription>
          <Link to="/forgot-password" className="font-medium underline underline-offset-4">
            Ask for a new link
          </Link>
        </AlertDescription>
      </Alert>
    )
  }

  return (
    <form onSubmit={form.handleSubmit(onSubmit)} noValidate>
      <FieldGroup>
        <p className="text-sm text-muted-foreground">
          For <span className="font-medium text-foreground">{email}</span>
        </p>
        <Field data-invalid={!!errors.password}>
          <FieldLabel htmlFor="reset-password">New password</FieldLabel>
          <Input
            id="reset-password"
            type="password"
            autoComplete="new-password"
            autoFocus
            aria-invalid={!!errors.password}
            aria-describedby={describedBy(errors.password && 'reset-password-error')}
            {...form.register('password')}
          />
          <FieldError id="reset-password-error" errors={[errors.password]} />
        </Field>
        <Field data-invalid={!!errors.password_confirmation}>
          <FieldLabel htmlFor="reset-password-confirmation">Repeat the new password</FieldLabel>
          <Input
            id="reset-password-confirmation"
            type="password"
            autoComplete="new-password"
            aria-invalid={!!errors.password_confirmation}
            aria-describedby={describedBy(errors.password_confirmation && 'reset-password-confirmation-error')}
            {...form.register('password_confirmation')}
          />
          <FieldError id="reset-password-confirmation-error" errors={[errors.password_confirmation]} />
        </Field>
        <Button type="submit" disabled={reset.isPending}>
          {reset.isPending && <Spinner />}
          Set new password
        </Button>
      </FieldGroup>
    </form>
  )
}
