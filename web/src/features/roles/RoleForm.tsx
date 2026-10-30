import { useMemo } from 'react'
import { zodResolver } from '@hookform/resolvers/zod'
import { Controller, useForm } from 'react-hook-form'
import { toast } from 'sonner'
import { z } from 'zod'
import { Button } from '@/components/ui/button'
import { Checkbox } from '@/components/ui/checkbox'
import {
  Field,
  FieldContent,
  FieldDescription,
  FieldError,
  FieldGroup,
  FieldLabel,
  FieldLegend,
  FieldSet,
} from '@/components/ui/field'
import { Input } from '@/components/ui/input'
import { Spinner } from '@/components/ui/spinner'
import { applyFieldErrors, errorMessage } from '@/lib/apiErrors'
import { describedBy } from '@/lib/aria'
import { useCurrentOrganization } from '@/stores/auth'
import type { Permission, PermissionInfo, RoleDetails } from '@/types/api'
import { useCreateRole, useUpdateRole } from './api'

const schema = z.object({
  name: z.string().trim().min(1, 'Enter a name.').max(50, 'At most 50 characters.'),
  permissions: z.array(z.string()),
})

type Values = z.infer<typeof schema>

interface RoleFormProps {
  /** The role to edit; none to create one. */
  role?: RoleDetails
  /** The permission catalogue (the caller has loaded it). */
  catalogue: PermissionInfo[]
  onSaved: () => void
  onCancel: () => void
}

/**
 * A role's name and permissions, as checkboxes grouped by area. Permissions you
 * don't have are shown but disabled: the API refuses to grant them anyway.
 */
export function RoleForm({ role, catalogue, onSaved, onCancel }: RoleFormProps) {
  const createRole = useCreateRole()
  const updateRole = useUpdateRole()
  const saving = createRole.isPending || updateRole.isPending
  const mine = useCurrentOrganization()?.permissions ?? []

  const form = useForm<Values>({
    resolver: zodResolver(schema),
    defaultValues: { name: role?.name ?? '', permissions: role?.permissions ?? [] },
  })
  const { errors } = form.formState

  // Recomputed only when the catalogue changes (a computed() in Vue).
  const groups = useMemo(() => groupByArea(catalogue), [catalogue])

  function onSubmit(values: Values) {
    const body = { name: values.name.trim(), permissions: values.permissions as Permission[] }
    const callbacks = {
      onSuccess: () => {
        toast.success(role ? 'Role saved' : 'Role created')
        onSaved()
      },
      onError: (error: Error) => {
        if (!applyFieldErrors(error, form.setError, ['name', 'permissions'])) {
          toast.error(role ? 'Could not save the role' : 'Could not create the role', { description: errorMessage(error) })
        }
      },
    }

    if (role) {
      updateRole.mutate({ id: role.id, ...body }, callbacks)
    } else {
      createRole.mutate(body, callbacks)
    }
  }

  return (
    <form onSubmit={form.handleSubmit(onSubmit)} noValidate>
      <FieldGroup>
        <Field data-invalid={!!errors.name}>
          <FieldLabel htmlFor="role-name">Name</FieldLabel>
          <Input
            id="role-name"
            autoFocus
            autoComplete="off"
            placeholder="e.g. Viewer"
            aria-invalid={!!errors.name}
            aria-describedby={describedBy(errors.name && 'role-name-error')}
            {...form.register('name')}
          />
          <FieldError id="role-name-error" errors={[errors.name]} />
        </Field>

        {/* One field holds the whole list; each checkbox adds or removes its value. */}
        <Controller
          control={form.control}
          name="permissions"
          render={({ field }) => (
            <div className="grid gap-5">
              {groups.map(([group, permissions]) => (
                <FieldSet key={group}>
                  <FieldLegend variant="label">{group}</FieldLegend>
                  <FieldGroup className="gap-3">
                    {permissions.map((permission) => {
                      const id = `permission-${permission.value.replace('.', '-')}`
                      const lacking = !mine.includes(permission.value)
                      const checked = field.value.includes(permission.value)
                      return (
                        <Field key={permission.value} orientation="horizontal" data-disabled={lacking || undefined}>
                          {/* A role you may edit never has one you lack (it must do
                              strictly less than yours), so a disabled box is unchecked. */}
                          <Checkbox
                            id={id}
                            checked={checked}
                            disabled={lacking}
                            aria-describedby={lacking ? `${id}-lacking` : undefined}
                            onCheckedChange={(next) =>
                              field.onChange(
                                next === true
                                  ? [...field.value, permission.value]
                                  : field.value.filter((value) => value !== permission.value),
                              )
                            }
                          />
                          <FieldContent>
                            <FieldLabel htmlFor={id} className="font-normal">
                              {permission.label}
                            </FieldLabel>
                            {lacking && (
                              <FieldDescription id={`${id}-lacking`}>Your role doesn't have this.</FieldDescription>
                            )}
                          </FieldContent>
                        </Field>
                      )
                    })}
                  </FieldGroup>
                </FieldSet>
              ))}
            </div>
          )}
        />
        <FieldError errors={[errors.permissions]} />

        <div className="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <Button type="button" variant="outline" onClick={onCancel}>
            Cancel
          </Button>
          <Button type="submit" disabled={saving}>
            {saving && <Spinner />}
            {role ? 'Save' : 'Create role'}
          </Button>
        </div>
      </FieldGroup>
    </form>
  )
}

/** [['Tasks', [...]], ['Members', [...]]], keeping the catalogue's order. */
function groupByArea(catalogue: PermissionInfo[]): [string, PermissionInfo[]][] {
  const groups = new Map<string, PermissionInfo[]>()
  for (const permission of catalogue) {
    groups.set(permission.group, [...(groups.get(permission.group) ?? []), permission])
  }
  return [...groups]
}
