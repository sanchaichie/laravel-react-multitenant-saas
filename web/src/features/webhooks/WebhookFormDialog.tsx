import { type ReactNode, useState } from 'react'
import { useIsMutating } from '@tanstack/react-query'
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog'
import type { WebhookEndpoint } from '@/types/api'
import { CREATE_WEBHOOK_KEY } from './api'
import { WebhookForm } from './WebhookForm'

interface WebhookFormDialogProps {
  /** The button that opens it. */
  children: ReactNode
  onCreated: (webhook: WebhookEndpoint, secret: string) => void
}

/** Add a webhook. On success it closes and hands the secret to the caller (shown once). */
export function WebhookFormDialog({ children, onCreated }: WebhookFormDialogProps) {
  const [open, setOpen] = useState(false)
  // While it's being created, the dialog stays open: closing it would unmount the form,
  // and its callback with the secret (shown only once) would never run.
  const saving = useIsMutating({ mutationKey: CREATE_WEBHOOK_KEY }) > 0

  return (
    <Dialog open={open} onOpenChange={(next) => (saving ? undefined : setOpen(next))}>
      <DialogTrigger asChild>{children}</DialogTrigger>
      <DialogContent className="max-h-[90dvh] overflow-y-auto sm:max-w-lg">
        <DialogHeader>
          <DialogTitle>Add a webhook</DialogTitle>
          <DialogDescription>
            We'll send a signed POST to this URL when the events you choose happen. It carries the task's content.
          </DialogDescription>
        </DialogHeader>
        <WebhookForm
          onCreated={(webhook, secret) => {
            setOpen(false)
            onCreated(webhook, secret)
          }}
          onCancel={() => setOpen(false)}
        />
      </DialogContent>
    </Dialog>
  )
}
