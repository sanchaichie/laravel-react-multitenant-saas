import { Link, useParams } from 'react-router'
import { Badge } from '@/components/ui/badge'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Skeleton } from '@/components/ui/skeleton'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { Button } from '@/components/ui/button'
import { AdminActionDialog } from '@/features/admin/AdminActionDialog'
import { AdminGate } from '@/features/admin/AdminGate'
import { BackLink, Detail } from '@/features/admin/AdminParts'
import { AdminUserBadges } from '@/features/admin/AdminUserBadges'
import { type AdminUserAction, useAdminUser, useAdminUserAction, useStartSupportView } from '@/features/admin/api'
import { parseId } from '@/features/admin/parseId'
import { formatDate, formatDateTime } from '@/lib/format'
import { useAuth } from '@/stores/auth'
import type { AdminUserDetail } from '@/types/api'

/** Platform admin: one user, their organizations and roles. Each visit is in the platform log. */
export function AdminUserPage() {
  const userId = parseId(useParams().id)
  const user = useAdminUser(userId)

  return (
    // A malformed id is a missing page, like an unknown one.
    <AdminGate error={user.error} notFound={userId === null} onRetry={() => void user.refetch()}>
      <div className="grid gap-6">
        <BackLink to="/admin/users">Users</BackLink>
        {!user.data ? (
          <div className="grid gap-4" aria-label="Loading the user">
            <Skeleton className="h-8 w-64" />
            <Skeleton className="h-40 w-full" />
          </div>
        ) : (
          <UserDetails user={user.data.data} />
        )}
      </div>
    </AdminGate>
  )
}

function UserDetails({ user }: { user: AdminUserDetail }) {
  return (
    <>
      <title>{`${user.name} · Platform admin · React SaaS`}</title>
      <div className="grid gap-2">
        <h1 className="text-2xl font-semibold break-words">{user.name}</h1>
        <p className="text-sm break-all text-muted-foreground">{user.email}</p>
        <AdminUserBadges user={user} />
      </div>

      <Card>
        <CardHeader>
          <CardTitle>
            <h2>Account</h2>
          </CardTitle>
        </CardHeader>
        <CardContent>
          <dl className="grid gap-4 text-sm sm:grid-cols-3">
            <Detail label="Joined">{formatDate(user.created_at)}</Detail>
            <Detail label="Signed-in devices">{user.devices_count}</Detail>
            <Detail label="Last active">{user.last_active_at ? formatDateTime(user.last_active_at) : 'Never'}</Detail>
          </dl>
        </CardContent>
      </Card>

      <ActionsCard user={user} />

      <Card>
        <CardHeader>
          <CardTitle>
            <h2>Organizations</h2>
          </CardTitle>
          <CardDescription>
            {user.organizations.length === 0 ? 'Not a member of any organization.' : 'Where they are a member, and their role.'}
          </CardDescription>
        </CardHeader>
        {user.organizations.length > 0 && (
          <CardContent>
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Organization</TableHead>
                  <TableHead>Role</TableHead>
                  <TableHead className="hidden sm:table-cell">Joined</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {user.organizations.map((organization) => (
                  <TableRow key={organization.id}>
                    <TableCell className="max-w-56">
                      <Link
                        to={`/admin/organizations/${organization.id}`}
                        className="block truncate font-medium underline-offset-4 hover:underline"
                      >
                        {organization.name}
                      </Link>
                    </TableCell>
                    <TableCell>
                      <Badge variant={organization.role.is_owner ? 'default' : 'secondary'}>{organization.role.name}</Badge>
                    </TableCell>
                    <TableCell className="hidden sm:table-cell">{formatDate(organization.joined_at)}</TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </CardContent>
        )}
      </Card>
    </>
  )
}

/**
 * What support can do for this user. Platform admins (you included) are managed
 * from the command line, so their page says so instead; the API refuses anyway.
 */
function ActionsCard({ user }: { user: AdminUserDetail }) {
  const myId = useAuth((s) => s.user?.id)
  const blocked = user.id === myId ? "This is you: admins can't act on themselves here." : user.is_platform_admin
    ? 'Platform admins are managed from the command line (admin:revoke first).'
    : null

  return (
    <Card>
      <CardHeader>
        <CardTitle>
          <h2>Actions</h2>
        </CardTitle>
        <CardDescription>{blocked ?? 'Each one asks for a reason, kept in the platform log.'}</CardDescription>
      </CardHeader>
      {!blocked && (
        <CardContent className="flex flex-wrap gap-2">
          {/* Nothing to view for a suspended user (their sessions are refused) or one in no organization. */}
          {!user.suspended_at && user.organizations.length > 0 && <ViewAsUser user={user} />}
          {user.two_factor_enabled && (
            <UserAction
              userId={user.id}
              action="two-factor/reset"
              label="Reset two-factor"
              description={`Turns off two-factor for ${user.name} and signs them out of every device. They log in with their password and can set it up again. They get an email.`}
              success="Two-factor was reset."
              destructive
            />
          )}
          {user.devices_count > 0 && (
            <UserAction
              userId={user.id}
              action="sign-out"
              label="Sign out everywhere"
              description={`Signs ${user.name} out of all ${user.devices_count === 1 ? 'their device' : `${user.devices_count} devices`}. They can log in again.`}
              success="Signed out of every device."
            />
          )}
          {user.suspended_at ? (
            <UserAction
              userId={user.id}
              action="unsuspend"
              label="Unsuspend"
              description={`${user.name} can log in again.`}
              success="The suspension was lifted."
            />
          ) : (
            <UserAction
              userId={user.id}
              action="suspend"
              label="Suspend"
              description={`${user.name} is signed out everywhere and can't log in or reset their password until unsuspended. No email is sent.`}
              success="The user is suspended."
              destructive
            />
          )}
        </CardContent>
      )}
    </Card>
  )
}

interface UserActionProps {
  userId: number
  action: AdminUserAction
  label: string
  description: string
  success: string
  destructive?: boolean
}

function UserAction({ userId, action, label, description, success, destructive = false }: UserActionProps) {
  const mutation = useAdminUserAction(userId, action)

  return (
    <AdminActionDialog
      title={`${label}?`}
      description={description}
      confirmLabel={label}
      destructive={destructive}
      onConfirm={mutation.mutateAsync}
      successMessage={success}
    >
      <Button variant={destructive ? 'destructive' : 'outline'}>{label}</Button>
    </AdminActionDialog>
  )
}

function ViewAsUser({ user }: { user: AdminUserDetail }) {
  const start = useStartSupportView(user.id)

  return (
    <AdminActionDialog
      title={`View the app as ${user.name}?`}
      description="Opens the app as they see it, read-only, until it expires (the banner says when). Task descriptions, summaries, chats and their devices stay hidden. They get an email; each organization you look at gets a “Platform admin” entry in its audit log."
      confirmLabel="View as user"
      onConfirm={start.mutateAsync}
      successMessage={`Viewing as ${user.name}.`}
    >
      <Button>View as user</Button>
    </AdminActionDialog>
  )
}

