import { useState } from 'react'
import { zodResolver } from '@hookform/resolvers/zod'
import { useForm } from 'react-hook-form'
import { toast } from 'sonner'
import { z } from 'zod'
import { Alert, AlertDescription } from '@/components/ui/alert'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from '@/components/ui/dialog'
import { Field, FieldError, FieldLabel } from '@/components/ui/field'
import { Input } from '@/components/ui/input'
import { Spinner } from '@/components/ui/spinner'
import { ApiError } from '@/lib/api'
import { applyFieldErrors, errorMessage } from '@/lib/apiErrors'
import { describedBy } from '@/lib/aria'
import { useDeleteAccount } from './api'

const schema = z.object({ password: z.string().min(1, 'Enter your password.') })
type Values = z.infer<typeof schema>

export function DeleteAccountCard() {
  const [open, setOpen] = useState(false)

  return (
    <Card className="border-destructive/40">
      <CardHeader>
        <CardTitle>
          <h2>Delete account</h2>
        </CardTitle>
        <CardDescription>
          Organizations where you're the only member are deleted with all their data. You leave the others. This can't
          be undone.
        </CardDescription>
      </CardHeader>
      <CardContent>
        <Dialog open={open} onOpenChange={setOpen}>
          <DialogTrigger asChild>
            <Button variant="destructive">Delete account…</Button>
          </DialogTrigger>
          <DialogContent className="sm:max-w-md">
            <DialogHeader>
              <DialogTitle>Delete your account?</DialogTitle>
              <DialogDescription>Enter your password to confirm. This can't be undone.</DialogDescription>
            </DialogHeader>
            {/* Inside DialogContent, which unmounts on close: each opening gets an empty form. */}
            <DeleteAccountForm onCancel={() => setOpen(false)} />
          </DialogContent>
        </Dialog>
      </CardContent>
    </Card>
  )
}

function DeleteAccountForm({ onCancel }: { onCancel: () => void }) {
  const remove = useDeleteAccount()
  const form = useForm<Values>({ resolver: zodResolver(schema), defaultValues: { password: '' } })
  const { errors } = form.formState
  // The organizations that block it, as the API words it (422 on `account`).
  const blocked = remove.error instanceof ApiError ? remove.error.data?.errors?.account?.[0] : undefined

  function onSubmit({ password }: Values) {
    remove.mutate(password, {
      onError: (error) => {
        const isBlocked = error instanceof ApiError && error.data?.errors?.account
        if (!isBlocked && !applyFieldErrors(error, form.setError, ['password'])) {
          toast.error('Could not delete your account', { description: errorMessage(error) })
        }
      },
    })
  }

  return (
    <form onSubmit={form.handleSubmit(onSubmit)} noValidate className="grid gap-4">
      {blocked && (
        <Alert variant="destructive">
          <AlertDescription>
            {/* The API names what to do (make someone Owner, or cancel a plan); this says where. */}
            {blocked} Switch to that organization from the menu at the top of the sidebar to do it there.
          </AlertDescription>
        </Alert>
      )}
      <Field data-invalid={!!errors.password}>
        <FieldLabel htmlFor="delete-account-password">Password</FieldLabel>
        <Input
          id="delete-account-password"
          type="password"
          autoComplete="current-password"
          autoFocus
          aria-invalid={!!errors.password}
          aria-describedby={describedBy(errors.password && 'delete-account-password-error')}
          {...form.register('password')}
        />
        <FieldError id="delete-account-password-error" errors={[errors.password]} />
      </Field>
      <DialogFooter>
        <Button type="button" variant="outline" onClick={onCancel}>
          Cancel
        </Button>
        <Button type="submit" variant="destructive" disabled={remove.isPending}>
          {remove.isPending && <Spinner />}
          Delete my account
        </Button>
      </DialogFooter>
    </form>
  )
}
