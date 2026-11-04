import { zodResolver } from '@hookform/resolvers/zod'
import { MailCheckIcon } from 'lucide-react'
import { useForm } from 'react-hook-form'
import { toast } from 'sonner'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import { Button } from '@/components/ui/button'
import { Field, FieldError, FieldGroup, FieldLabel } from '@/components/ui/field'
import { Input } from '@/components/ui/input'
import { Spinner } from '@/components/ui/spinner'
import { applyFieldErrors, errorMessage } from '@/lib/apiErrors'
import { describedBy } from '@/lib/aria'
import { useForgotPassword } from './api'
import { forgotPasswordSchema, type ForgotPasswordValues } from './schemas'

export function ForgotPasswordForm() {
  const forgot = useForgotPassword()
  const form = useForm<ForgotPasswordValues>({ resolver: zodResolver(forgotPasswordSchema), defaultValues: { email: '' } })
  const { errors } = form.formState

  function onSubmit({ email }: ForgotPasswordValues) {
    forgot.mutate(email, {
      onError: (error) => {
        if (!applyFieldErrors(error, form.setError, ['email'])) {
          toast.error('Could not send the link', { description: errorMessage(error) })
        }
      },
    })
  }

  // The API answers the same whether an account uses the address or not.
  if (forgot.isSuccess) {
    return (
      <Alert>
        <MailCheckIcon aria-hidden />
        <AlertTitle>Check your email</AlertTitle>
        <AlertDescription>{forgot.data.message} The link works for 60 minutes.</AlertDescription>
      </Alert>
    )
  }

  return (
    <form onSubmit={form.handleSubmit(onSubmit)} noValidate>
      <FieldGroup>
        <Field data-invalid={!!errors.email}>
          <FieldLabel htmlFor="forgot-email">Email</FieldLabel>
          <Input
            id="forgot-email"
            type="email"
            autoComplete="email"
            autoFocus
            aria-invalid={!!errors.email}
            aria-describedby={describedBy(errors.email && 'forgot-email-error')}
            {...form.register('email')}
          />
          <FieldError id="forgot-email-error" errors={[errors.email]} />
        </Field>
        <Button type="submit" disabled={forgot.isPending}>
          {forgot.isPending && <Spinner />}
          Send reset link
        </Button>
      </FieldGroup>
    </form>
  )
}
