import { zodResolver } from '@hookform/resolvers/zod'
import { useForm } from 'react-hook-form'
import { toast } from 'sonner'
import { z } from 'zod'
import { MailIcon } from 'lucide-react'
import { Alert, AlertAction, AlertDescription, AlertTitle } from '@/components/ui/alert'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Field, FieldDescription, FieldError, FieldGroup, FieldLabel } from '@/components/ui/field'
import { Input } from '@/components/ui/input'
import { Spinner } from '@/components/ui/spinner'
import { applyFieldErrors, errorMessage } from '@/lib/apiErrors'
import { describedBy } from '@/lib/aria'
import type { User } from '@/types/api'
import { useCancelEmailChange, useChangeEmail } from './api'

const schema = z.object({
  email: z.email({ error: (issue) => (issue.input === '' ? 'Enter the new email.' : 'Enter a valid email address.') }).max(255),
  current_password: z.string().min(1, 'Enter your current password.'),
})
type Values = z.infer<typeof schema>

export function EmailCard({ user }: { user: User }) {
  const change = useChangeEmail()
  const cancel = useCancelEmailChange()
  const form = useForm<Values>({ resolver: zodResolver(schema), defaultValues: { email: '', current_password: '' } })
  const { errors } = form.formState

  function onSubmit(values: Values) {
    change.mutate(values, {
      onSuccess: ({ data }) => {
        form.reset()
        toast.success('Check your new inbox', {
          description: `Follow the link we sent to ${data.pending_email} to finish the change.`,
        })
      },
      onError: (error) => {
        if (!applyFieldErrors(error, form.setError, ['email', 'current_password'])) {
          toast.error('Could not change your email', { description: errorMessage(error) })
        }
      },
    })
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle>
          <h2>Email</h2>
        </CardTitle>
        <CardDescription className="flex flex-wrap items-center gap-2">
          <span className="break-all">{user.email}</span>
          {user.email_verified ? <Badge variant="secondary">Verified</Badge> : <Badge variant="outline">Not verified</Badge>}
        </CardDescription>
      </CardHeader>
      <CardContent className="grid gap-4">
        {user.pending_email && (
          <Alert>
            <MailIcon aria-hidden />
            <AlertTitle>Waiting for confirmation</AlertTitle>
            <AlertDescription>
              Your email changes to <span className="font-medium break-all text-foreground">{user.pending_email}</span> once
              you follow the link we sent there (while logged in). Until then, keep using {user.email}.
            </AlertDescription>
            <AlertAction>
              <Button
                variant="outline"
                size="sm"
                disabled={cancel.isPending}
                onClick={() =>
                  cancel.mutate(undefined, {
                    onSuccess: () => toast.success('Email change cancelled'),
                    onError: (error) => toast.error('Could not cancel it', { description: errorMessage(error) }),
                  })
                }
              >
                Cancel change
              </Button>
            </AlertAction>
          </Alert>
        )}
        <form onSubmit={form.handleSubmit(onSubmit)} noValidate>
          <FieldGroup>
            <Field data-invalid={!!errors.email}>
              <FieldLabel htmlFor="account-email">New email</FieldLabel>
              <Input
                id="account-email"
                type="email"
                autoComplete="email"
                aria-invalid={!!errors.email}
                aria-describedby={describedBy('account-email-hint', errors.email && 'account-email-error')}
                {...form.register('email')}
              />
              <FieldDescription id="account-email-hint">
                We'll send a link to the new address; the change happens when you follow it, and your other devices are
                then signed out. This address gets a notice.
              </FieldDescription>
              <FieldError id="account-email-error" errors={[errors.email]} />
            </Field>
            <Field data-invalid={!!errors.current_password}>
              <FieldLabel htmlFor="account-email-password">Current password</FieldLabel>
              <Input
                id="account-email-password"
                type="password"
                autoComplete="current-password"
                aria-invalid={!!errors.current_password}
                aria-describedby={describedBy(errors.current_password && 'account-email-password-error')}
                {...form.register('current_password')}
              />
              <FieldError id="account-email-password-error" errors={[errors.current_password]} />
            </Field>
            <div>
              <Button type="submit" disabled={change.isPending}>
                {change.isPending && <Spinner />}
                Send confirmation link
              </Button>
            </div>
          </FieldGroup>
        </form>
      </CardContent>
    </Card>
  )
}
