import { zodResolver } from '@hookform/resolvers/zod'
import { useForm } from 'react-hook-form'
import { Link } from 'react-router'
import { toast } from 'sonner'
import { Alert, AlertDescription } from '@/components/ui/alert'
import { Button } from '@/components/ui/button'
import { Field, FieldError, FieldGroup, FieldLabel } from '@/components/ui/field'
import { Input } from '@/components/ui/input'
import { Spinner } from '@/components/ui/spinner'
import { applyFieldErrors, errorMessage } from '@/lib/apiErrors'
import { describedBy } from '@/lib/aria'
import { useLogin } from './api'
import { loginSchema, type LoginValues } from './schemas'

interface LoginFormProps {
  /** Kept by the page when coming back from the code step. */
  defaultEmail?: string
  /** Why the page came back here (an expired code step). */
  notice?: string
  /** Two-factor is on: the page shows the code step. */
  onChallenge: (challenge: string, email: string) => void
}

export function LoginForm({ defaultEmail = '', notice, onChallenge }: LoginFormProps) {
  const login = useLogin()
  // register() wires each input to the form without React state: typing doesn't
  // re-render this component, only errors and submitting do.
  const form = useForm<LoginValues>({
    resolver: zodResolver(loginSchema),
    defaultValues: { email: defaultEmail, password: '' },
  })
  const { errors } = form.formState

  // Runs only once the schema passes. On success the session is stored, and
  // RequireGuest moves on to ?redirect= (see useLogin); with two-factor on, the
  // answer is a challenge and the page asks for a code.
  function onSubmit(values: LoginValues) {
    login.mutate(values, {
      onSuccess: (result) => {
        if ('challenge' in result) {
          onChallenge(result.challenge, values.email)
        }
      },
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
        {notice && (
          <Alert>
            <AlertDescription>{notice}</AlertDescription>
          </Alert>
        )}
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
          <div className="flex items-center justify-between gap-2">
            <FieldLabel htmlFor="login-password">Password</FieldLabel>
            <Link to="/forgot-password" className="text-sm text-muted-foreground underline-offset-4 hover:underline">
              Forgot password?
            </Link>
          </div>
          <Input
            id="login-password"
            type="password"
            autoComplete="current-password"
            // Back from the code step with the email filled in: the password is next.
            autoFocus={defaultEmail !== ''}
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
