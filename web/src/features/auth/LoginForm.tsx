import { zodResolver } from '@hookform/resolvers/zod'
import { useForm } from 'react-hook-form'
import { toast } from 'sonner'
import { Button } from '@/components/ui/button'
import { Field, FieldError, FieldGroup, FieldLabel } from '@/components/ui/field'
import { Input } from '@/components/ui/input'
import { Spinner } from '@/components/ui/spinner'
import { applyFieldErrors, errorMessage } from '@/lib/apiErrors'
import { describedBy } from '@/lib/aria'
import { useLogin } from './api'
import { loginSchema, type LoginValues } from './schemas'

export function LoginForm() {
  const login = useLogin()
  // register() wires each input to the form without React state: typing doesn't
  // re-render this component, only errors and submitting do.
  const form = useForm<LoginValues>({
    resolver: zodResolver(loginSchema),
    defaultValues: { email: '', password: '' },
  })
  const { errors } = form.formState

  // Runs only once the schema passes. On success the session is stored, and
  // RequireGuest moves on to ?redirect= (see useLogin).
  function onSubmit(values: LoginValues) {
    login.mutate(values, {
      onError: (error) => {
        if (!applyFieldErrors(error, form.setError, ['email', 'password'])) {
          toast.error('Could not log in', { description: errorMessage(error) })
        }
      },
    })
  }

  return (
    <form onSubmit={form.handleSubmit(onSubmit)} noValidate>
      <FieldGroup>
        <Field data-invalid={!!errors.email}>
          <FieldLabel htmlFor="login-email">Email</FieldLabel>
          <Input
            id="login-email"
            type="email"
            autoComplete="email"
            aria-invalid={!!errors.email}
            aria-describedby={describedBy(errors.email && 'login-email-error')}
            {...form.register('email')}
          />
          <FieldError id="login-email-error" errors={[errors.email]} />
        </Field>

        <Field data-invalid={!!errors.password}>
          <FieldLabel htmlFor="login-password">Password</FieldLabel>
          <Input
            id="login-password"
            type="password"
            autoComplete="current-password"
            aria-invalid={!!errors.password}
            aria-describedby={describedBy(errors.password && 'login-password-error')}
            {...form.register('password')}
          />
          <FieldError id="login-password-error" errors={[errors.password]} />
        </Field>

        <Button type="submit" disabled={login.isPending}>
          {login.isPending && <Spinner />}
          Log in
        </Button>
      </FieldGroup>
    </form>
  )
}
