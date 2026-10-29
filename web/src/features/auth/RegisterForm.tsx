import { zodResolver } from '@hookform/resolvers/zod'
import { useForm } from 'react-hook-form'
import { toast } from 'sonner'
import { Button } from '@/components/ui/button'
import { Field, FieldDescription, FieldError, FieldGroup, FieldLabel } from '@/components/ui/field'
import { Input } from '@/components/ui/input'
import { Spinner } from '@/components/ui/spinner'
import { applyFieldErrors, errorMessage } from '@/lib/apiErrors'
import { describedBy } from '@/lib/aria'
import { useRegister } from './api'
import { registerSchema, type RegisterValues } from './schemas'

export function RegisterForm() {
  const registerUser = useRegister()
  const form = useForm<RegisterValues>({
    resolver: zodResolver(registerSchema),
    defaultValues: { name: '', email: '', password: '', password_confirmation: '' },
  })
  const { errors } = form.formState

  function onSubmit(values: RegisterValues) {
    registerUser.mutate(values, {
      onError: (error) => {
        if (!applyFieldErrors(error, form.setError, ['name', 'email', 'password', 'password_confirmation'])) {
          toast.error('Could not create your account', { description: errorMessage(error) })
        }
      },
    })
  }

  return (
    <form onSubmit={form.handleSubmit(onSubmit)} noValidate>
      <FieldGroup>
        <Field data-invalid={!!errors.name}>
          <FieldLabel htmlFor="register-name">Name</FieldLabel>
          <Input
            id="register-name"
            autoComplete="name"
            aria-invalid={!!errors.name}
            aria-describedby={describedBy(errors.name && 'register-name-error')}
            {...form.register('name')}
          />
          <FieldError id="register-name-error" errors={[errors.name]} />
        </Field>

        <Field data-invalid={!!errors.email}>
          <FieldLabel htmlFor="register-email">Email</FieldLabel>
          <Input
            id="register-email"
            type="email"
            autoComplete="email"
            aria-invalid={!!errors.email}
            aria-describedby={describedBy(errors.email && 'register-email-error')}
            {...form.register('email')}
          />
          <FieldError id="register-email-error" errors={[errors.email]} />
        </Field>

        <Field data-invalid={!!errors.password}>
          <FieldLabel htmlFor="register-password">Password</FieldLabel>
          <Input
            id="register-password"
            type="password"
            autoComplete="new-password"
            aria-invalid={!!errors.password}
            aria-describedby={describedBy(
              !errors.password && 'register-password-hint',
              errors.password && 'register-password-error',
            )}
            {...form.register('password')}
          />
          {/* The error replaces the hint, rather than repeating it. */}
          {!errors.password && <FieldDescription id="register-password-hint">At least 8 characters.</FieldDescription>}
          <FieldError id="register-password-error" errors={[errors.password]} />
        </Field>

        <Field data-invalid={!!errors.password_confirmation}>
          <FieldLabel htmlFor="register-password-confirmation">Confirm password</FieldLabel>
          <Input
            id="register-password-confirmation"
            type="password"
            autoComplete="new-password"
            aria-invalid={!!errors.password_confirmation}
            aria-describedby={describedBy(errors.password_confirmation && 'register-password-confirmation-error')}
            {...form.register('password_confirmation')}
          />
          <FieldError id="register-password-confirmation-error" errors={[errors.password_confirmation]} />
        </Field>

        <Button type="submit" disabled={registerUser.isPending}>
          {registerUser.isPending && <Spinner />}
          Create account
        </Button>
      </FieldGroup>
    </form>
  )
}
