import { type ReactNode, useState } from 'react'
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog'
import type { PermissionInfo, RoleDetails } from '@/types/api'
import { RoleForm } from './RoleForm'

interface RoleFormDialogProps {
  /** The button that opens it. As the dialog's trigger, it gets focus back on close. */
  children: ReactNode
  role?: RoleDetails
  catalogue: PermissionInfo[]
}

/** New role, or Edit role when given one. */
export function RoleFormDialog({ children, role, catalogue }: RoleFormDialogProps) {
  const [open, setOpen] = useState(false)

  return (
    <Dialog open={open} onOpenChange={setOpen}>
      <DialogTrigger asChild>{children}</DialogTrigger>
      <DialogContent className="max-h-[90dvh] overflow-y-auto sm:max-w-lg">
        <DialogHeader>
          <DialogTitle>{role ? `Edit ${role.name}` : 'New role'}</DialogTitle>
          <DialogDescription>
            {role
              ? 'Changes apply to everyone with this role at once.'
              : 'Name it, then choose what people with it may do. Everyone can see tasks and members.'}
          </DialogDescription>
        </DialogHeader>
        {/* DialogContent unmounts when closed: each opening gets a fresh form. */}
        <RoleForm role={role} catalogue={catalogue} onSaved={() => setOpen(false)} onCancel={() => setOpen(false)} />
      </DialogContent>
    </Dialog>
  )
}
