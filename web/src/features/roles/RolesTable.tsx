import { toast } from 'sonner'
import { ConfirmDialog } from '@/components/ConfirmDialog'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { errorMessage } from '@/lib/apiErrors'
import type { PermissionInfo, RoleDetails } from '@/types/api'
import { useDeleteRole } from './api'
import { RoleFormDialog } from './RoleFormDialog'

/**
 * The organization's roles. Edit and Delete show per each row's `can` (the API's
 * rules: only roles that can do strictly less than yours, never Owner).
 */
export function RolesTable({ roles, catalogue }: { roles: RoleDetails[]; catalogue: PermissionInfo[] }) {
  return (
    <Table>
      <TableHeader>
        <TableRow>
          <TableHead>Role</TableHead>
          <TableHead className="hidden sm:table-cell">Members</TableHead>
          <TableHead className="hidden md:table-cell">Allows</TableHead>
          <TableHead className="w-0">
            <span className="sr-only">Actions</span>
          </TableHead>
        </TableRow>
      </TableHeader>
      <TableBody>
        {roles.map((role) => (
          <RoleRow key={role.id} role={role} catalogue={catalogue} />
        ))}
      </TableBody>
    </Table>
  )
}

function RoleRow({ role, catalogue }: { role: RoleDetails; catalogue: PermissionInfo[] }) {
  const deleteRole = useDeleteRole()
  const summary = summarize(role, catalogue)
  const members = `${role.members_count} ${role.members_count === 1 ? 'member' : 'members'}`

  function onDelete() {
    deleteRole.mutate(role, {
      onSuccess: () => toast.success(`${role.name} was deleted`),
      // A 422 says how many members still have it.
      onError: (error) => toast.error(`Could not delete ${role.name}`, { description: errorMessage(error) }),
    })
  }

  return (
    <TableRow>
      <TableCell className="align-top whitespace-normal">
        <div className="grid min-w-0 gap-0.5">
          <span className="font-medium">
            {role.name}
            {role.is_owner && (
              <Badge variant="secondary" className="ml-2">
                Built in
              </Badge>
            )}
          </span>
          {/* On small screens the other columns are hidden: show them here. */}
          <span className="text-xs text-muted-foreground sm:hidden">{members}</span>
          <span className="text-xs text-muted-foreground md:hidden">{summary}</span>
        </div>
      </TableCell>
      <TableCell className="hidden align-top text-muted-foreground sm:table-cell">{members}</TableCell>
      <TableCell className="hidden align-top whitespace-normal text-muted-foreground md:table-cell">{summary}</TableCell>
      <TableCell className="align-top">
        <div className="flex justify-end gap-1">
          {role.can.update && (
            <RoleFormDialog role={role} catalogue={catalogue}>
              <Button variant="ghost" size="sm" aria-label={`Edit ${role.name}`}>
                Edit
              </Button>
            </RoleFormDialog>
          )}
          {role.can.delete && (
            <ConfirmDialog
              title={`Delete ${role.name}?`}
              description={
                role.members_count > 0
                  ? `${members} still ${role.members_count === 1 ? 'has' : 'have'} this role. Give them another role first.`
                  : 'Pending invitations with this role will stop working.'
              }
              confirmLabel="Delete"
              onConfirm={onDelete}
            >
              <Button variant="ghost" size="sm" aria-label={`Delete ${role.name}`} disabled={deleteRole.isPending}>
                Delete
              </Button>
            </ConfirmDialog>
          )}
        </div>
      </TableCell>
    </TableRow>
  )
}

/** "Tasks: create, manage · Members: invite": by area, in catalogue order. */
function summarize(role: RoleDetails, catalogue: PermissionInfo[]): string {
  if (role.is_owner) {
    return 'Everything, including permissions added later'
  }

  const parts = new Map<string, string[]>()
  for (const permission of catalogue) {
    if (role.permissions.includes(permission.value)) {
      // 'tasks.create' → 'create'
      const action = permission.value.split('.')[1] ?? permission.value
      parts.set(permission.group, [...(parts.get(permission.group) ?? []), action])
    }
  }

  return parts.size === 0
    ? 'Sees tasks and members only'
    : [...parts].map(([group, actions]) => `${group}: ${actions.join(', ')}`).join(' · ')
}
