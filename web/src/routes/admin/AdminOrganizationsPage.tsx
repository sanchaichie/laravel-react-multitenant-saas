import { Building2Icon } from 'lucide-react'
import { Link, Navigate } from 'react-router'
import { Badge } from '@/components/ui/badge'
import { Card, CardContent } from '@/components/ui/card'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { AdminGate } from '@/features/admin/AdminGate'
import { AdminListSkeleton } from '@/features/admin/AdminListSkeleton'
import { AdminPagination } from '@/features/admin/AdminPagination'
import { AdminSearchBox } from '@/features/admin/AdminSearchBox'
import { useAdminOrganizations } from '@/features/admin/api'
import { useAdminSearch } from '@/features/admin/useAdminSearch'
import { formatDate } from '@/lib/format'
import { cn } from '@/lib/utils'

/** Platform admin: every organization, searchable by name. */
export function AdminOrganizationsPage() {
  const { search, setQuery, pageLink } = useAdminSearch('/admin/organizations')
  const organizations = useAdminOrganizations(search)
  const meta = organizations.data?.meta

  if (meta && !organizations.isPlaceholderData && (search.page ?? 1) > Math.max(1, meta.last_page)) {
    return <Navigate to={pageLink(meta.last_page)} replace />
  }

  return (
    <AdminGate error={organizations.error} onRetry={() => void organizations.refetch()}>
      <div className="grid gap-6">
        <title>Organizations · Platform admin · React SaaS</title>
        <div>
          <h1 className="text-2xl font-semibold">Organizations</h1>
          <p className="text-sm text-muted-foreground">Every organization, newest first.</p>
        </div>

        <AdminSearchBox value={search.q ?? ''} onSearch={setQuery} label="Search organizations" placeholder="Search by name" />

        <Card>
          <CardContent>
            {!organizations.data ? (
              <AdminListSkeleton label="Loading organizations" />
            ) : organizations.data.data.length === 0 ? (
              <div className="flex flex-col items-center gap-3 px-4 py-12 text-center">
                <Building2Icon className="size-8 text-muted-foreground" aria-hidden />
                <h2 className="text-lg font-semibold">{search.q ? 'No organizations match' : 'No organizations yet'}</h2>
                <p className="max-w-sm text-sm text-muted-foreground">
                  {search.q ? 'Try part of the name.' : 'Every sign-up creates one; they will show here.'}
                </p>
              </div>
            ) : (
              <div
                className={cn('transition-opacity', organizations.isPlaceholderData && 'opacity-60')}
                aria-busy={organizations.isPlaceholderData}
              >
                <Table>
                  <TableHeader>
                    <TableRow>
                      <TableHead>Name</TableHead>
                      <TableHead className="text-right">Members</TableHead>
                      <TableHead>Plan</TableHead>
                      <TableHead className="hidden sm:table-cell">Created</TableHead>
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {organizations.data.data.map((organization) => (
                      <TableRow key={organization.id}>
                        <TableCell className="max-w-56">
                          <Link
                            to={`/admin/organizations/${organization.id}`}
                            className="block truncate font-medium underline-offset-4 hover:underline"
                          >
                            {organization.name}
                          </Link>
                        </TableCell>
                        <TableCell className="text-right tabular-nums">{organization.members_count}</TableCell>
                        <TableCell>
                          <Badge variant={organization.plan === 'free' ? 'outline' : 'default'} className="capitalize">
                            {organization.plan}
                          </Badge>
                        </TableCell>
                        <TableCell className="hidden sm:table-cell">{formatDate(organization.created_at)}</TableCell>
                      </TableRow>
                    ))}
                  </TableBody>
                </Table>
              </div>
            )}
          </CardContent>
        </Card>

        {meta && <AdminPagination currentPage={meta.current_page} lastPage={meta.last_page} pageLink={pageLink} />}
      </div>
    </AdminGate>
  )
}
