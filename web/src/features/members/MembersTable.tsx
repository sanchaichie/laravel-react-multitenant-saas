import { toast } from 'sonner'
import { ConfirmDialog } from '@/components/ConfirmDialog'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { roleLabel, sortRoles } from '@/features/organizations/api'
import { errorMessage } from '@/lib/apiErrors'
import { formatDate } from '@/lib/format'
import { useCurrentOrganization } from '@/stores/auth'
import type { Member, OrganizationRole } from '@/types/api'
import { useChangeRole, useRemoveMember } from './api'

/**
 * The members of the current organization. Which controls show comes from each
 * row's `can` and `assignable_roles` (the API's policy), never from rules here.
 */
export function MembersTable({ members }: { members: Member[] }) {
  return (
    <Table>
      <TableHeader>
        <TableRow>
          <TableHead>Name</TableHead>
          <TableHead className="hidden md:table-cell">Email</TableHead>
          <TableHead>Role</TableHead>
          <TableHead className="hidden sm:table-cell">Joined</TableHead>
          <TableHead className="w-0">
            <span className="sr-only">Actions</span>
          </TableHead>
        </TableRow>
      </TableHeader>
      <TableBody>
        {members.map((member) => (
          // key: React keeps each row's state (a pending select) with its member.
          <MemberRow key={member.id} member={member} />
        ))}
      </TableBody>
    </Table>
  )
}

function MemberRow({ member }: { member: Member }) {
  const organization = useCurrentOrganization()
  const changeRole = useChangeRole()
  const removeMember = useRemoveMember()

  function onRoleChange(role: string) {
    changeRole.mutate(
      { member, role: role as OrganizationRole },
      {
        onSuccess: () => toast.success(`${member.name} is now ${roleLabel(role as OrganizationRole)}`),
        onError: (error) => toast.error('Could not change the role', { description: errorMessage(error) }),
      },
    )
  }

  function onRemove() {
    // Leaving's toast and redirect live in useRemoveMember (this row unmounts on leave).
    removeMember.mutate(member, {
      onSuccess: () => {
        if (!member.is_me) {
          toast.success(`${member.name} was removed`)
        }
      },
      onError: (error) =>
        toast.error(member.is_me ? 'Could not leave' : 'Could not remove', { description: errorMessage(error) }),
    })
  }

  return (
    <TableRow>
      <TableCell>
        <div className="grid min-w-0">
          <span className="truncate font-medium">
            {member.name}
            {member.is_me && (
              <Badge variant="secondary" className="ml-2">
                You
              </Badge>
            )}
          </span>
          {/* On small screens the email column is hidden: show it here. */}
          <span className="truncate text-xs text-muted-foreground md:hidden">{member.email}</span>
        </div>
      </TableCell>
      <TableCell className="hidden text-muted-foreground md:table-cell">{member.email}</TableCell>
      <TableCell>
        {member.can.update ? (
          // Controlled by server data: it shows the member's role until the change
          // succeeds and the list is refetched.
          <Select value={member.role} onValueChange={onRoleChange} disabled={changeRole.isPending}>
            <SelectTrigger size="sm" className="w-28" aria-label={`Role of ${member.name}`}>
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              {sortRoles([member.role, ...member.assignable_roles]).map((role) => (
                <SelectItem key={role} value={role}>
                  {roleLabel(role)}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        ) : (
          <span>{roleLabel(member.role)}</span>
        )}
      </TableCell>
      <TableCell className="hidden text-muted-foreground sm:table-cell">{formatDate(member.joined_at)}</TableCell>
      <TableCell className="text-right">
        {member.can.delete && (
          <ConfirmDialog
            title={member.is_me ? `Leave ${organization?.name ?? 'this organization'}?` : `Remove ${member.name}?`}
            description={
              member.is_me
                ? "You'll lose access to its data. You can only come back with a new invitation."
                : `${member.name} will lose access to ${organization?.name ?? 'this organization'}.`
            }
            confirmLabel={member.is_me ? 'Leave' : 'Remove'}
            onConfirm={onRemove}
          >
            <Button variant="ghost" size="sm" disabled={removeMember.isPending}>
              {member.is_me ? 'Leave' : 'Remove'}
            </Button>
          </ConfirmDialog>
        )}
      </TableCell>
    </TableRow>
  )
}
