import { type ReactNode, useState } from 'react'
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
  AlertDialogTrigger,
} from '@/components/ui/alert-dialog'

interface ConfirmDialogProps {
  /** The button that opens it (rendered as is: asChild). */
  children: ReactNode
  title: string
  description: ReactNode
  confirmLabel: string
  /** Called when confirmed; the dialog closes, and the caller shows the result (toast). */
  onConfirm: () => void
  destructive?: boolean
}

/**
 * "Are you sure?" around any button: the trigger comes in as `children`, the
 * rest as props, like a modal component with a default slot and emits in Vue.
 */
export function ConfirmDialog({ children, title, description, confirmLabel, onConfirm, destructive = true }: ConfirmDialogProps) {
  const [open, setOpen] = useState(false)

  return (
    <AlertDialog open={open} onOpenChange={setOpen}>
      <AlertDialogTrigger asChild>{children}</AlertDialogTrigger>
      <AlertDialogContent>
        <AlertDialogHeader>
          <AlertDialogTitle>{title}</AlertDialogTitle>
          <AlertDialogDescription>{description}</AlertDialogDescription>
        </AlertDialogHeader>
        <AlertDialogFooter>
          <AlertDialogCancel>Cancel</AlertDialogCancel>
          <AlertDialogAction variant={destructive ? 'destructive' : 'default'} onClick={onConfirm}>
            {confirmLabel}
          </AlertDialogAction>
        </AlertDialogFooter>
      </AlertDialogContent>
    </AlertDialog>
  )
}
