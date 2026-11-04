import { UsersIcon } from 'lucide-react'
import { Link, Navigate } from 'react-router'
import { Card, CardContent } from '@/components/ui/card'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { AdminGate } from '@/features/admin/AdminGate'
import { AdminListSkeleton } from '@/features/admin/AdminListSkeleton'
import { AdminPagination } from '@/features/admin/AdminPagination'
import { AdminSearchBox } from '@/features/admin/AdminSearchBox'
import { AdminUserBadges } from '@/features/admin/AdminUserBadges'
import { useAdminUsers } from '@/features/admin/api'
import { useAdminSearch } from '@/features/admin/useAdminSearch'
import { formatDate } from '@/lib/format'
import { cn } from '@/lib/utils'

/** Platform admin: every user, searchable by name or email. Loaded only when opened (a lazy route). */
export function AdminUsersPage() {
  const { search, setQuery, pageLink } = useAdminSearch('/admin/users')
  const users = useAdminUsers(search)
  const meta = users.data?.meta

  // A page past the end (an old link): go to the last one.
  if (meta && !users.isPlaceholderData && (search.page ?? 1) > Math.max(1, meta.last_page)) {
    return <Navigate to={pageLink(meta.last_page)} replace />
  }

  return (
    <AdminGate error={users.error} onRetry={() => void users.refetch()}>
      <div className="grid gap-6">
        <title>Users · Platform admin · React SaaS</title>
        <div>
          <h1 className="text-2xl font-semibold">Users</h1>
          <p className="text-sm text-muted-foreground">Everyone with an account, newest first.</p>
        </div>

        <AdminSearchBox value={search.q ?? ''} onSearch={setQuery} label="Search users" placeholder="Search name or email" />

        <Card>
          <CardContent>
            {!users.data ? (
              <AdminListSkeleton label="Loading users" />
            ) : users.data.data.length === 0 ? (
              <Empty search={search.q} />
            ) : (
              <div className={cn('transition-opacity', users.isPlaceholderData && 'opacity-60')} aria-busy={users.isPlaceholderData}>
                <Table>
                  <TableHeader>
                    <TableRow>
                      <TableHead>Name</TableHead>
                      <TableHead className="hidden md:table-cell">Email</TableHead>
                      <TableHead>Status</TableHead>
                      <TableHead className="hidden text-right sm:table-cell">Organizations</TableHead>
                      <TableHead className="hidden lg:table-cell">Joined</TableHead>
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {users.data.data.map((user) => (
                      <TableRow key={user.id}>
                        <TableCell className="max-w-48">
                          <Link to={`/admin/users/${user.id}`} className="block truncate font-medium underline-offset-4 hover:underline">
                            {user.name}
                          </Link>
                          {/* The email column is hidden on small screens: it goes under the name. */}
                          <span className="block truncate text-xs text-muted-foreground md:hidden">{user.email}</span>
                        </TableCell>
                        <TableCell className="hidden max-w-64 truncate md:table-cell">{user.email}</TableCell>
                        <TableCell>
                          <AdminUserBadges user={user} />
                        </TableCell>
                        <TableCell className="hidden text-right tabular-nums sm:table-cell">{user.organizations_count}</TableCell>
                        <TableCell className="hidden lg:table-cell">{formatDate(user.created_at)}</TableCell>
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

function Empty({ search }: { search?: string }) {
  return (
    <div className="flex flex-col items-center gap-3 px-4 py-12 text-center">
      <UsersIcon className="size-8 text-muted-foreground" aria-hidden />
      <h2 className="text-lg font-semibold">{search ? 'No users match' : 'No users yet'}</h2>
      <p className="max-w-sm text-sm text-muted-foreground">
        {search ? 'Try part of their name or email address.' : 'People who sign up will show here.'}
      </p>
    </div>
  )
}
