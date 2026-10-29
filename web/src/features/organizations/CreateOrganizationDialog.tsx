import type { RefObject } from 'react'
import { zodResolver } from '@hookform/resolvers/zod'
import { useForm } from 'react-hook-form'
import { toast } from 'sonner'
import { z } from 'zod'
import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Field, FieldError, FieldGroup, FieldLabel } from '@/components/ui/field'
import { Input } from '@/components/ui/input'
import { Spinner } from '@/components/ui/spinner'
import { applyFieldErrors, errorMessage } from '@/lib/apiErrors'
import { describedBy } from '@/lib/aria'
import { useCreateOrganization } from './api'

const schema = z.object({
  name: z.string().trim().min(1, 'Enter a name.').max(255, 'At most 255 characters.'),
})

type Values = z.infer<typeof schema>

interface CreateOrganizationDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  /** Where focus goes back to on close (the menu item that opened it is gone by then). */
  returnFocusTo?: RefObject<HTMLElement | null>
}

/**
 * A controlled dialog: the parent owns `open` (useState) and passes it down with
 * a setter, the React version of v-model:open.
 */
export function CreateOrganizationDialog({ open, onOpenChange, returnFocusTo }: CreateOrganizationDialogProps) {
  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent
        className="sm:max-w-md"
        onCloseAutoFocus={(event) => {
          if (returnFocusTo?.current) {
            event.preventDefault()
            returnFocusTo.current.focus()
          }
        }}
      >
        <DialogHeader>
          <DialogTitle>Create organization</DialogTitle>
          <DialogDescription>You'll be its owner, and it becomes your current organization.</DialogDescription>
        </DialogHeader>
        {/* Rendered only while open (DialogContent unmounts when closed), so each
            opening starts with an empty form. */}
        <CreateOrganizationForm onCreated={() => onOpenChange(false)} onCancel={() => onOpenChange(false)} />
      </DialogContent>
    </Dialog>
  )
}

function CreateOrganizationForm({ onCreated, onCancel }: { onCreated: () => void; onCancel: () => void }) {
  const createOrganization = useCreateOrganization()
  const form = useForm<Values>({ resolver: zodResolver(schema), defaultValues: { name: '' } })
  const { errors } = form.formState

  function onSubmit(values: Values) {
    createOrganization.mutate(values.name, {
      onSuccess: ({ data }) => {
        toast.success('Organization created', { description: `You're now working in ${data.name}.` })
        onCreated()
      },
      onError: (error) => {
        if (!applyFieldErrors(error, form.setError, ['name'])) {
          toast.error('Could not create the organization', { description: errorMessage(error) })
        }
      },
    })
  }

  return (
    <form onSubmit={form.handleSubmit(onSubmit)} noValidate>
      <FieldGroup>
        <Field data-invalid={!!errors.name}>
          <FieldLabel htmlFor="organization-name">Name</FieldLabel>
          <Input
            id="organization-name"
            autoFocus
            autoComplete="organization"
            aria-invalid={!!errors.name}
            aria-describedby={describedBy(errors.name && 'organization-name-error')}
            {...form.register('name')}
          />
          <FieldError id="organization-name-error" errors={[errors.name]} />
        </Field>
        <DialogFooter>
          <Button type="button" variant="outline" onClick={onCancel}>
            Cancel
          </Button>
          <Button type="submit" disabled={createOrganization.isPending}>
            {createOrganization.isPending && <Spinner />}
            Create
          </Button>
        </DialogFooter>
      </FieldGroup>
    </form>
  )
}
