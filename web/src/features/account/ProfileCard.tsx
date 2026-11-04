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
import type { User } from '@/types/api'
import { useUpdateProfile } from './api'

const schema = z.object({ name: z.string().trim().min(1, 'Enter your name.').max(255, 'At most 255 characters.') })
type Values = z.infer<typeof schema>

export function ProfileCard({ user }: { user: User }) {
  const update = useUpdateProfile()
  // Each card has its own form: its own values, errors and submit.
  const form = useForm<Values>({ resolver: zodResolver(schema), defaultValues: { name: user.name } })
  const { errors, isDirty } = form.formState

  function onSubmit(values: Values) {
    update.mutate(
      { name: values.name.trim() },
      {
        onError: (error) => {
          if (!applyFieldErrors(error, form.setError, ['name'])) {
            toast.error('Could not save your name', { description: errorMessage(error) })
          }
        },
      },
    )
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle>
          <h2>Profile</h2>
        </CardTitle>
        <CardDescription>How you appear to the people in your organizations.</CardDescription>
      </CardHeader>
      <CardContent>
        <form onSubmit={form.handleSubmit(onSubmit)} noValidate>
          <FieldGroup>
            <Field data-invalid={!!errors.name}>
              <FieldLabel htmlFor="account-name">Name</FieldLabel>
              <Input
                id="account-name"
                autoComplete="name"
                aria-invalid={!!errors.name}
                aria-describedby={describedBy(errors.name && 'account-name-error')}
                {...form.register('name')}
              />
              <FieldError id="account-name-error" errors={[errors.name]} />
            </Field>
            <div>
              <Button type="submit" disabled={update.isPending || !isDirty}>
                {update.isPending && <Spinner />}
                Save name
              </Button>
            </div>
          </FieldGroup>
        </form>
      </CardContent>
    </Card>
  )
}
