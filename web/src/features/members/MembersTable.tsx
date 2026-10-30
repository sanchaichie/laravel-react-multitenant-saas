import { toast } from 'sonner'
import { ConfirmDialog } from '@/components/ConfirmDialog'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { sortRoles } from '@/features/roles/api'
import { errorMessage } from '@/lib/apiErrors'
import { formatDate } from '@/lib/format'
import { useCurrentOrganization } from '@/stores/auth'
import type { Member } from '@/types/api'
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

  // The choices: their current role plus the ones the API says you may give them.
  const roles = sortRoles([member.role, ...member.assignable_roles])

  // A Select's values are strings: the role's id.
  function onRoleChange(value: string) {
    const role = roles.find((choice) => String(choice.id) === value)
    if (!role) return

    changeRole.mutate(
      { member, role },
      {
        onSuccess: () => toast.success(`${member.name} now has the ${role.name} role`),
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
          <Select value={String(member.role.id)} onValueChange={onRoleChange} disabled={changeRole.isPending}>
            <SelectTrigger size="sm" className="w-36" aria-label={`Role of ${member.name}`}>
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              {roles.map((role) => (
                <SelectItem key={role.id} value={String(role.id)}>
                  {role.name}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        ) : (
          <span>{member.role.name}</span>
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
