import { toast } from 'sonner'
import { ConfirmDialog } from '@/components/ConfirmDialog'
import { ErrorState } from '@/components/ErrorState'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Skeleton } from '@/components/ui/skeleton'
import { roleLabel } from '@/features/organizations/api'
import { errorMessage } from '@/lib/apiErrors'
import { formatDate } from '@/lib/format'
import type { Invitation } from '@/types/api'
import { useInvitations, useRevokeInvitation } from './api'

/** Invitations sent but not accepted yet, with Revoke. */
export function PendingInvitations() {
  const invitations = useInvitations()
  const revoke = useRevokeInvitation()

  function onRevoke(invitation: Invitation) {
    revoke.mutate(invitation, {
      onSuccess: () => toast.success('Invitation revoked', { description: `The link sent to ${invitation.email} no longer works.` }),
      onError: (error) => toast.error('Could not revoke the invitation', { description: errorMessage(error) }),
    })
  }

  if (invitations.isPending) {
    return <Skeleton className="h-10 w-full" />
  }

  if (invitations.isError) {
    return (
      <ErrorState title="Could not load the invitations" description={errorMessage(invitations.error)}>
        <Button variant="outline" onClick={() => void invitations.refetch()}>
          Retry
        </Button>
      </ErrorState>
    )
  }

  if (invitations.data.data.length === 0) {
    return <p className="text-sm text-muted-foreground">No pending invitations.</p>
  }

  return (
    <ul className="divide-y">
      {invitations.data.data.map((invitation) => (
        <li key={invitation.id} className="flex items-center justify-between gap-3 py-2.5">
          <div className="grid min-w-0">
            <span className="truncate text-sm font-medium">{invitation.email}</span>
            <span className="text-xs text-muted-foreground">
              {roleLabel(invitation.role)}
              {invitation.invited_by && ` · invited by ${invitation.invited_by}`}
              {' · '}
              {invitation.expired ? (
                <Badge variant="outline" className="text-xs">
                  Expired
                </Badge>
              ) : (
                `expires ${formatDate(invitation.expires_at)}`
              )}
            </span>
          </div>
          {invitation.can.delete && (
            <ConfirmDialog
              title={`Revoke the invitation to ${invitation.email}?`}
              description="Its link stops working. You can invite them again later."
              confirmLabel="Revoke"
              onConfirm={() => onRevoke(invitation)}
            >
              <Button variant="ghost" size="sm" disabled={revoke.isPending}>
                Revoke
              </Button>
            </ConfirmDialog>
          )}
        </li>
      ))}
    </ul>
  )
}
