import type { ReactNode } from 'react'
import { ScrollTextIcon } from 'lucide-react'
import { Navigate } from 'react-router'
import { ErrorState } from '@/components/ErrorState'
import { Button } from '@/components/ui/button'
import { Card, CardContent } from '@/components/ui/card'
import { Skeleton } from '@/components/ui/skeleton'
import { Spinner } from '@/components/ui/spinner'
import { useAuditActions, useAuditLog } from '@/features/audit/api'
import { AuditFilters } from '@/features/audit/AuditFilters'
import { AuditLogList } from '@/features/audit/AuditLogList'
import { auditFiltersToSearchParams, hasAuditFilters } from '@/features/audit/filters'
import { useAuditFilters } from '@/features/audit/useAuditFilters'
import { usePermissions } from '@/features/roles/api'
import { useCan } from '@/hooks/useCan'
import { ApiError } from '@/lib/api'
import { errorMessage } from '@/lib/apiErrors'
import { useCurrentOrganization } from '@/stores/auth'

export function AuditLogPage() {
  const organization = useCurrentOrganization()
  const canView = useCan('audit_log.view')
  const { filters, setFilters, clearFilters } = useAuditFilters()
  const actions = useAuditActions(canView)
  // An action the API doesn't know (an old or hand-edited link) is dropped below
  // before asking for the list, which would answer 422.
  const unknownAction = Boolean(filters.action && actions.data && !actions.data.data.some((a) => a.value === filters.action))
  const log = useAuditLog(filters, canView && actions.isSuccess && !unknownAction)
  // Only for permission labels in role changes: without it they show as values.
  const permissions = usePermissions()

  if (!organization) {
    return (
      <ErrorState
        title="No organization selected"
        description="Choose or create one from the switcher in the sidebar to see its audit log."
      />
    )
  }

  // Your role may have lost the permission since the page loaded: the API says 403.
  const forbidden = [actions.error, log.error].some((error) => error instanceof ApiError && error.status === 403)
  if (!canView || forbidden) {
    return (
      <ErrorState
        title="You don't have access to the audit log"
        description="Ask an owner for a role with “View the audit log”."
      />
    )
  }

  if (unknownAction) {
    const query = auditFiltersToSearchParams({ ...filters, action: undefined }).toString()
    return <Navigate to={query ? `/audit-log?${query}` : '/audit-log'} replace />
  }

  // A failed "Load more" keeps the pages already shown (its message is below them).
  const failed = actions.isError ? actions : log.isError && !log.isFetchNextPageError ? log : null
  // Every page loaded so far, as one list.
  const entries = log.data?.pages.flatMap((page) => page.data) ?? []

  return (
    <div className="grid gap-6">
      <title>Audit log · React SaaS</title>
      <div>
        <h1 className="text-2xl font-semibold">Audit log</h1>
        <p className="text-sm text-muted-foreground">Who changed what in {organization.name}, newest first.</p>
      </div>

      {actions.data && <AuditFilters filters={filters} actions={actions.data.data} onChange={setFilters} onClear={clearFilters} />}

      <Card>
        <CardContent>
          {failed ? (
            <ErrorState title="Could not load the audit log" description={errorMessage(failed.error)}>
              <Button variant="outline" onClick={() => void failed.refetch()}>
                Retry
              </Button>
            </ErrorState>
          ) : !log.data ? (
            <div className="grid gap-2" aria-label="Loading the audit log">
              <Skeleton className="h-9 w-full" />
              <Skeleton className="h-9 w-full" />
              <Skeleton className="h-9 w-full" />
            </div>
          ) : entries.length === 0 ? (
            hasAuditFilters(filters) ? (
              <EmptyState title="No entries match these filters" description="Try another filter, or clear them to see everything.">
                <Button variant="outline" onClick={clearFilters}>
                  Clear filters
                </Button>
              </EmptyState>
            ) : (
              <EmptyState
                title="Nothing recorded yet"
                description="Changes to tasks, members, invitations and roles will show here."
              />
            )
          ) : (
            <AuditLogList entries={entries} permissions={permissions.data?.data ?? []} />
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
  )
}

function EmptyState({ title, description, children }: { title: string; description: string; children?: ReactNode }) {
  return (
    <div className="flex flex-col items-center gap-3 px-4 py-12 text-center">
      <ScrollTextIcon className="size-8 text-muted-foreground" aria-hidden />
      <h2 className="text-lg font-semibold">{title}</h2>
      <p className="max-w-sm text-sm text-muted-foreground">{description}</p>
      {children ? <div className="mt-2">{children}</div> : null}
    </div>
  )
}
