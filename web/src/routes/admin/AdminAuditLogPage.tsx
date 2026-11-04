import { ScrollTextIcon } from 'lucide-react'
import { Link } from 'react-router'
import { Button } from '@/components/ui/button'
import { Card, CardContent } from '@/components/ui/card'
import { Spinner } from '@/components/ui/spinner'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { AdminGate } from '@/features/admin/AdminGate'
import { AdminListSkeleton } from '@/features/admin/AdminListSkeleton'
import { usePlatformLog } from '@/features/admin/api'
import { errorMessage } from '@/lib/apiErrors'
import { formatDateTime } from '@/lib/format'
import type { PlatformAuditLogEntry } from '@/types/api'

/** Platform admin: what admins looked at or did, newest first. */
export function AdminAuditLogPage() {
  const log = usePlatformLog()
  // A failed "Load more" keeps the pages already shown (its message is below them).
  const failed = log.isError && !log.isFetchNextPageError
  const entries = log.data?.pages.flatMap((page) => page.data) ?? []

  return (
    <AdminGate error={failed ? log.error : null} onRetry={() => void log.refetch()}>
      <div className="grid gap-6">
        <title>Platform log · Platform admin · React SaaS</title>
        <div>
          <h1 className="text-2xl font-semibold">Platform log</h1>
          <p className="text-sm text-muted-foreground">What platform admins looked at or changed, newest first. Organizations don't see it.</p>
        </div>

        <Card>
          <CardContent>
            {!log.data ? (
              <AdminListSkeleton label="Loading the platform log" />
            ) : entries.length === 0 ? (
              <div className="flex flex-col items-center gap-3 px-4 py-12 text-center">
                <ScrollTextIcon className="size-8 text-muted-foreground" aria-hidden />
                <h2 className="text-lg font-semibold">Nothing recorded yet</h2>
                <p className="max-w-sm text-sm text-muted-foreground">Opening a user or an organization, and granting admins, shows here.</p>
              </div>
            ) : (
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>When</TableHead>
                    <TableHead>Admin</TableHead>
                    <TableHead>Action</TableHead>
                    <TableHead className="hidden md:table-cell">IP address</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {entries.map((entry) => (
                    <TableRow key={entry.id}>
                      <TableCell className="whitespace-nowrap">{formatDateTime(entry.created_at)}</TableCell>
                      <TableCell className="max-w-48">
                        {entry.actor ? <span className="block truncate">{entry.actor.name}</span> : <span className="text-muted-foreground">Console</span>}
                      </TableCell>
                      <TableCell>
                        <ActionCell entry={entry} />
                      </TableCell>
                      <TableCell className="hidden font-mono text-xs md:table-cell">{entry.ip_address ?? '—'}</TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            )}
          </CardContent>
        </Card>

        {log.hasNextPage && (
          <div className="flex justify-center">
            <Button variant="outline" onClick={() => void log.fetchNextPage()} disabled={log.isFetchingNextPage}>
              {log.isFetchingNextPage && <Spinner />}
              Load more
            </Button>
          </div>
        )}
        {log.isFetchNextPageError && (
          <p role="alert" className="text-center text-sm text-destructive">
            Could not load more entries. {errorMessage(log.error)}
          </p>
        )}
      </div>
    </AdminGate>
  )
}

function subjectLink(entry: PlatformAuditLogEntry): string | null {
  const { subject } = entry
  if (!subject?.id) return null
  if (subject.type === 'user') return `/admin/users/${subject.id}`
  if (subject.type === 'organization') return `/admin/organizations/${subject.id}`
  return null
}

function ActionCell({ entry }: { entry: PlatformAuditLogEntry }) {
  const link = subjectLink(entry)
  const label = entry.subject?.label
  const reason = typeof entry.metadata.reason === 'string' ? entry.metadata.reason : null

  return (
    <div className="grid gap-0.5">
      <span>
        {entry.action_label}
        {label && (
          <>
            {': '}
            {link ? (
              <Link to={link} className="font-medium break-all underline-offset-4 hover:underline">
                {label}
              </Link>
            ) : (
              <span className="font-medium break-all">{label}</span>
            )}
          </>
        )}
      </span>
      {/* Only ever an admin's own words, or a code like two_factor_disabled. */}
      {reason && <span className="text-xs text-muted-foreground">{reasonLabel(reason)}</span>}
    </div>
  )
}

function reasonLabel(reason: string): string {
  return reason === 'two_factor_disabled' ? 'Two-factor was turned off' : `Reason: ${reason}`
}
