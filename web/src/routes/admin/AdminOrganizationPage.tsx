import { Link, useParams } from 'react-router'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Skeleton } from '@/components/ui/skeleton'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { AdminActionDialog } from '@/features/admin/AdminActionDialog'
import { AdminGate } from '@/features/admin/AdminGate'
import { BackLink, Detail } from '@/features/admin/AdminParts'
import { useAdminOrganization, useMakeOwner } from '@/features/admin/api'
import { parseId } from '@/features/admin/parseId'
import { BudgetCard } from '@/features/aiUsage/BudgetCard'
import { formatDate } from '@/lib/format'
import { useAuth } from '@/stores/auth'
import type { AdminOrganizationDetail } from '@/types/api'

/** Platform admin: one organization's plan, AI budget, counts and members. Never task content. */
export function AdminOrganizationPage() {
  const organizationId = parseId(useParams().id)
  const organization = useAdminOrganization(organizationId)

  return (
    <AdminGate error={organization.error} notFound={organizationId === null} onRetry={() => void organization.refetch()}>
      <div className="grid gap-6">
        <BackLink to="/admin/organizations">Organizations</BackLink>
        {!organization.data ? (
          <div className="grid gap-4" aria-label="Loading the organization">
            <Skeleton className="h-8 w-64" />
            <Skeleton className="h-40 w-full" />
          </div>
        ) : (
          <OrganizationDetails organization={organization.data.data} />
        )}
      </div>
    </AdminGate>
  )
}

function OrganizationDetails({ organization }: { organization: AdminOrganizationDetail }) {
  const { plan, subscription, counts } = organization
  const myId = useAuth((s) => s.user?.id)
  const makeOwner = useMakeOwner(organization.id)

  return (
    <>
      <title>{`${organization.name} · Platform admin · React SaaS`}</title>
      <div className="grid gap-1">
        <h1 className="text-2xl font-semibold break-words">{organization.name}</h1>
        <p className="text-sm text-muted-foreground">Created {formatDate(organization.created_at)}</p>
      </div>

      <div className="grid gap-6 lg:grid-cols-2">
        <Card>
          <CardHeader>
            <CardTitle>
              <h2 className="flex items-center gap-2">
                Plan
                <Badge variant={plan.is_paid ? 'default' : 'outline'}>{plan.name}</Badge>
              </h2>
            </CardTitle>
            <CardDescription>
              {subscription
                ? `Subscription ${subscription.status.replaceAll('_', ' ')} since ${formatDate(subscription.created_at)}${
                    subscription.ends_at ? `, ends ${formatDate(subscription.ends_at)}` : ''
                  }.`
                : 'Never subscribed.'}
            </CardDescription>
          </CardHeader>
          <CardContent>
            <dl className="grid grid-cols-3 gap-4 text-sm">
              <Detail label="Members">{counts.members}</Detail>
              <Detail label="Tasks">{counts.tasks}</Detail>
              <Detail label="Invitations">{counts.pending_invitations}</Detail>
            </dl>
          </CardContent>
        </Card>
        <BudgetCard budget={organization.ai_budget} />
      </div>

      <Card>
        <CardHeader>
          <CardTitle>
            <h2>Members</h2>
          </CardTitle>
          <CardDescription>
            {counts.members > organization.members.length
              ? `The first ${organization.members.length} of ${counts.members}, by join date.`
              : 'By join date.'}
          </CardDescription>
        </CardHeader>
        <CardContent>
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
              {organization.members.map((member) => (
                <TableRow key={member.id}>
                  <TableCell className="max-w-48">
                    <Link to={`/admin/users/${member.id}`} className="block truncate font-medium underline-offset-4 hover:underline">
                      {member.name}
                    </Link>
                    <span className="block truncate text-xs text-muted-foreground md:hidden">{member.email}</span>
                  </TableCell>
                  <TableCell className="hidden max-w-64 truncate md:table-cell">{member.email}</TableCell>
                  <TableCell>
                    <Badge variant={member.role.is_owner ? 'default' : 'secondary'}>{member.role.name}</Badge>
                  </TableCell>
                  <TableCell className="hidden sm:table-cell">{formatDate(member.joined_at)}</TableCell>
                  <TableCell className="text-right">
                    {/* Not for owners, platform admins or yourself: the API refuses those too. */}
                    {!member.role.is_owner && !member.is_platform_admin && member.id !== myId && (
                      <AdminActionDialog
                        title={`Make ${member.name} an Owner?`}
                        description={`They get every permission in ${organization.name}, including members, roles and billing, and an email. The organization's audit log shows "Platform admin" made the change.`}
                        confirmLabel="Make Owner"
                        onConfirm={(reason) => makeOwner.mutateAsync({ userId: member.id, reason })}
                        successMessage={`${member.name} is now an Owner.`}
                      >
                        <Button variant="outline" size="sm" className="whitespace-nowrap">
                          Make Owner
                        </Button>
                      </AdminActionDialog>
                    )}
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </CardContent>
      </Card>
    </>
  )
}
