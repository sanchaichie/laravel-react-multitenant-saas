import { zodResolver } from '@hookform/resolvers/zod'
import { useForm } from 'react-hook-form'
import { toast } from 'sonner'
import { z } from 'zod'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Field, FieldError, FieldGroup, FieldLabel } from '@/components/ui/field'
import { Input } from '@/components/ui/input'
import { Spinner } from '@/components/ui/spinner'
import { applyFieldErrors, errorMessage } from '@/lib/apiErrors'
import { describedBy } from '@/lib/aria'
import { useChangePassword } from './api'

const schema = z
  .object({
    current_password: z.string().min(1, 'Enter your current password.'),
    password: z.string().min(8, 'At least 8 characters.'),
    password_confirmation: z.string().min(1, 'Repeat the new password.'),
  })
  .refine((values) => values.password === values.password_confirmation, {
    message: 'The passwords don\'t match.',
    path: ['password_confirmation'],
  })
type Values = z.infer<typeof schema>

const FIELDS = [
  { name: 'current_password', label: 'Current password', autoComplete: 'current-password' },
  { name: 'password', label: 'New password', autoComplete: 'new-password' },
  { name: 'password_confirmation', label: 'Repeat the new password', autoComplete: 'new-password' },
] as const

export function PasswordCard() {
  const change = useChangePassword()
  const form = useForm<Values>({
    resolver: zodResolver(schema),
    defaultValues: { current_password: '', password: '', password_confirmation: '' },
  })
  const { errors } = form.formState

  function onSubmit(values: Values) {
    change.mutate(values, {
      onSuccess: () => {
        form.reset()
        toast.success('Password changed', { description: 'Your other devices were signed out.' })
      },
      onError: (error) => {
        if (!applyFieldErrors(error, form.setError, FIELDS.map((f) => f.name))) {
          toast.error('Could not change your password', { description: errorMessage(error) })
        }
      },
    })
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle>
          <h2>Password</h2>
        </CardTitle>
        <CardDescription>Changing it signs out your other devices.</CardDescription>
      </CardHeader>
      <CardContent>
        <form onSubmit={form.handleSubmit(onSubmit)} noValidate>
          <FieldGroup>
            {FIELDS.map(({ name, label, autoComplete }) => {
              const id = `account-${name.replace('_', '-')}`
              return (
                <Field key={name} data-invalid={!!errors[name]}>
                  <FieldLabel htmlFor={id}>{label}</FieldLabel>
                  <Input
                    id={id}
                    type="password"
                    autoComplete={autoComplete}
                    aria-invalid={!!errors[name]}
                    aria-describedby={describedBy(errors[name] && `${id}-error`)}
                    {...form.register(name)}
                  />
                  <FieldError id={`${id}-error`} errors={[errors[name]]} />
                </Field>
              )
            })}
            <div>
              <Button type="submit" disabled={change.isPending}>
                {change.isPending && <Spinner />}
                Change password
              </Button>
            </div>
          </FieldGroup>
        </form>
      </CardContent>
    </Card>
  )
}
