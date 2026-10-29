import { zodResolver } from '@hookform/resolvers/zod'
import { Controller, useForm } from 'react-hook-form'
import { toast } from 'sonner'
import { z } from 'zod'
import { Button } from '@/components/ui/button'
import { Field, FieldError, FieldGroup, FieldLabel } from '@/components/ui/field'
import { Input } from '@/components/ui/input'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Spinner } from '@/components/ui/spinner'
import { roleLabel, sortRoles } from '@/features/organizations/api'
import { applyFieldErrors, errorMessage } from '@/lib/apiErrors'
import { describedBy } from '@/lib/aria'
import type { OrganizationRole } from '@/types/api'
import { useInvitations, useInvite } from './api'

const schema = z.object({
  email: z.email({ error: (issue) => (issue.input === '' ? 'Enter an email address.' : 'Enter a valid email address.') }),
  role: z.enum(['owner', 'admin', 'member']),
})

type Values = z.infer<typeof schema>

/**
 * Invite someone by email. The role choices are the ones the API says you may
 * invite with (`invitable_roles`); it checks again on submit.
 */
export function InviteForm() {
  const invite = useInvite()
  // The same query as the pending list below (one request, shared through the cache).
  const invitations = useInvitations()
  const roles: OrganizationRole[] = sortRoles(invitations.data?.meta.invitable_roles ?? ['member'])

  const form = useForm<Values>({ resolver: zodResolver(schema), defaultValues: { email: '', role: 'member' } })
  const { errors } = form.formState

  function onSubmit(values: Values) {
    invite.mutate(
      { email: values.email.trim().toLowerCase(), role: values.role },
      {
        onSuccess: ({ data }) => {
          toast.success('Invitation sent', { description: `We emailed ${data.email} a link to join.` })
          form.reset()
        },
        onError: (error) => {
          if (!applyFieldErrors(error, form.setError, ['email', 'role'])) {
            toast.error('Could not send the invitation', { description: errorMessage(error) })
          }
        },
      },
    )
  }

  return (
    <form onSubmit={form.handleSubmit(onSubmit)} noValidate>
      <FieldGroup className="sm:flex-row sm:items-start">
        <Field data-invalid={!!errors.email} className="sm:flex-1">
          <FieldLabel htmlFor="invite-email">Email</FieldLabel>
          <Input
            id="invite-email"
            type="email"
            autoComplete="off"
            placeholder="name@example.com"
            aria-invalid={!!errors.email}
            aria-describedby={describedBy(errors.email && 'invite-email-error')}
            {...form.register('email')}
          />
          <FieldError id="invite-email-error" errors={[errors.email]} />
        </Field>

        <Field data-invalid={!!errors.role} className="sm:w-36">
          <FieldLabel htmlFor="invite-role">Role</FieldLabel>
          {/* A Radix Select isn't a native input, so register() can't wire it:
              Controller passes value/onChange to it (a controlled field). */}
          <Controller
            control={form.control}
            name="role"
            render={({ field }) => (
              <Select value={field.value} onValueChange={field.onChange}>
                <SelectTrigger id="invite-role" className="w-full" aria-invalid={!!errors.role}>
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  {roles.map((role) => (
                    <SelectItem key={role} value={role}>
                      {roleLabel(role)}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            )}
          />
          <FieldError errors={[errors.role]} />
        </Field>

        <Button type="submit" className="sm:mt-6" disabled={invite.isPending}>
          {invite.isPending && <Spinner />}
          Invite
        </Button>
      </FieldGroup>
    </form>
  )
}
