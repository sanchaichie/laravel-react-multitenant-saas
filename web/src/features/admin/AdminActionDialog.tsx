import { type ReactNode, useId, useState } from 'react'
import { zodResolver } from '@hookform/resolvers/zod'
import { useForm } from 'react-hook-form'
import { toast } from 'sonner'
import { z } from 'zod'
import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog'
import { Field, FieldDescription, FieldError, FieldGroup, FieldLabel } from '@/components/ui/field'
import { Spinner } from '@/components/ui/spinner'
import { Textarea } from '@/components/ui/textarea'
import { ApiError } from '@/lib/api'
import { applyFieldErrors, errorMessage } from '@/lib/apiErrors'
import { describedBy } from '@/lib/aria'
import { adminRefusal } from './api'
import { ReconfirmForm } from './ReconfirmForm'

interface AdminActionDialogProps {
  /** The button that opens it (asChild). */
  children: ReactNode
  title: string
  /** What will happen, in a sentence or two. */
  description: ReactNode
  confirmLabel: string
  destructive?: boolean
  /** The request (a mutation's mutateAsync): resolves on success, rejects with the ApiError. */
  onConfirm: (reason: string) => Promise<unknown>
  /** The toast once it's done. */
  successMessage: string
}

/**
 * A platform admin action: what will happen, a required reason (kept in the
 * platform log only), and confirm. If the API asks for a fresh code first
 * (two_factor_reauth), the dialog swaps to the code, then sends the same action
 * again with the same reason.
 */
export function AdminActionDialog({ children, ...props }: AdminActionDialogProps) {
  const [open, setOpen] = useState(false)

  return (
    <Dialog open={open} onOpenChange={setOpen}>
      <DialogTrigger asChild>{children}</DialogTrigger>
      <DialogContent className="sm:max-w-md">
        {/* DialogContent unmounts when closed: each opening starts with an empty reason. */}
        <ActionForm {...props} onDone={() => setOpen(false)} />
      </DialogContent>
    </Dialog>
  )
}

const schema = z.object({
  reason: z.string().trim().min(10, 'At least 10 characters: say why, e.g. the ticket number.').max(500, 'At most 500 characters.'),
})
type Values = z.infer<typeof schema>

// The step as a discriminated union (a ref holding one of two shapes in Vue): the code
// step carries the reason it will resend, and TypeScript checks every branch.
type Step = { step: 'reason' } | { step: 'code'; reason: string }

function ActionForm({
  title,
  description,
  confirmLabel,
  destructive = false,
  onConfirm,
  successMessage,
  onDone,
}: Omit<AdminActionDialogProps, 'children'> & { onDone: () => void }) {
  const id = useId()
  const [step, setStep] = useState<Step>({ step: 'reason' })
  const [pending, setPending] = useState(false)
  // Made here, not in the reason step: the form (and what was typed) survives the code step.
  const form = useForm<Values>({ resolver: zodResolver(schema), defaultValues: { reason: '' } })
  const { errors } = form.formState

  async function run(reason: string) {
    setPending(true)
    try {
      await onConfirm(reason)
      toast.success(successMessage)
      onDone()
    } catch (error) {
      if (adminRefusal(error) === 'two_factor_reauth') {
        setStep({ step: 'code', reason })
        return
      }
      setStep({ step: 'reason' })
      // The reason under its field; anything else (an admin as the target, already
      // suspended, a member who left) above the buttons.
      if (!applyFieldErrors(error, form.setError, ['reason'])) {
        const message =
          error instanceof ApiError && error.status === 422 ? Object.values(error.data?.errors ?? {})[0]?.[0] : undefined
        form.setError('root', { type: 'server', message: message ?? errorMessage(error) })
      }
    } finally {
      setPending(false)
    }
  }

  const reasonId = `${id}-reason`
  const hintId = `${id}-hint`
  const errorId = `${id}-error`

  return (
    <>
      <DialogHeader>
        <DialogTitle>{title}</DialogTitle>
        <DialogDescription>{description}</DialogDescription>
      </DialogHeader>

      {step.step === 'code' ? (
        <div className="grid gap-3">
          <p className="text-sm text-muted-foreground">Confirm it's you first: admin actions need a code from the last few minutes.</p>
          {pending ? (
            <p className="flex items-center gap-2 text-sm" role="status">
              <Spinner /> {confirmLabel}…
            </p>
          ) : (
            // Then the same action again, with the reason already typed.
            <ReconfirmForm refetch={false} submitLabel={`Confirm and ${confirmLabel.toLowerCase()}`} onConfirmed={() => void run(step.reason)} />
          )}
        </div>
      ) : (
        <form onSubmit={form.handleSubmit(({ reason }) => run(reason.trim()))} noValidate>
          <FieldGroup>
            <Field data-invalid={!!errors.reason}>
              <FieldLabel htmlFor={reasonId}>Reason</FieldLabel>
              <Textarea
                id={reasonId}
                rows={3}
                maxLength={500}
                aria-invalid={!!errors.reason}
                aria-describedby={describedBy(hintId, errors.reason && errorId)}
                {...form.register('reason')}
              />
              <FieldDescription id={hintId}>10 to 500 characters. Kept in the platform log only; the user never sees it.</FieldDescription>
              <FieldError id={errorId} errors={[errors.reason]} />
            </Field>
            {errors.root && (
              <p role="alert" className="text-sm text-destructive">
                {errors.root.message}
              </p>
            )}
            <DialogFooter>
              <Button type="button" variant="outline" onClick={onDone}>
                Cancel
              </Button>
              <Button type="submit" variant={destructive ? 'destructive' : 'default'} disabled={pending}>
                {pending && <Spinner />}
                {confirmLabel}
              </Button>
            </DialogFooter>
          </FieldGroup>
        </form>
      )}
    </>
  )
}
