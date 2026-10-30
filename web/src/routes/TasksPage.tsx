import type { ReactNode } from 'react'
import { ChevronLeftIcon, ChevronRightIcon, ListTodoIcon, PlusIcon } from 'lucide-react'
import { Link, Navigate } from 'react-router'
import { ErrorState } from '@/components/ErrorState'
import { Button } from '@/components/ui/button'
import { Card, CardContent } from '@/components/ui/card'
import { Pagination, PaginationContent, PaginationItem } from '@/components/ui/pagination'
import { Skeleton } from '@/components/ui/skeleton'
import { useTasks } from '@/features/tasks/api'
import { filtersToSearchParams, hasActiveFilters } from '@/features/tasks/filters'
import { TaskFilters } from '@/features/tasks/TaskFilters'
import { TaskFormDialog } from '@/features/tasks/TaskFormDialog'
import { TasksTable } from '@/features/tasks/TasksTable'
import { useTaskFilters } from '@/features/tasks/useTaskFilters'
import { useCan } from '@/hooks/useCan'
import { errorMessage } from '@/lib/apiErrors'
import { cn } from '@/lib/utils'
import { useCurrentOrganization } from '@/stores/auth'
import type { TaskFilters as Filters } from '@/types/api'

export function TasksPage() {
  const organization = useCurrentOrganization()
  const { filters, setFilters, clearFilters } = useTaskFilters()
  const tasks = useTasks(filters)
  // A role without tasks.create (say, a viewer) only reads.
  const canCreate = useCan('tasks.create')

  if (!organization) {
    return (
      <ErrorState
        title="No organization selected"
        description="Choose or create one from the switcher in the sidebar to see its tasks."
      />
    )
  }

  const meta = tasks.data?.meta
  // A page past the end (tasks deleted, or an old link): go to the last one. <Navigate>
  // during render is how React Router redirects without an effect.
  if (meta && !tasks.isPlaceholderData && (filters.page ?? 1) > meta.last_page) {
    return <Navigate to={pageLink(filters, meta.last_page)} replace />
  }

  return (
    <div className="grid gap-6">
      <title>Tasks · React SaaS</title>
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="text-2xl font-semibold">Tasks</h1>
          <p className="text-sm text-muted-foreground">Everything to do in {organization.name}.</p>
        </div>
        {canCreate && (
          <TaskFormDialog>
            <Button>
              <PlusIcon aria-hidden />
              New task
            </Button>
          </TaskFormDialog>
        )}
      </div>

      <TaskFilters filters={filters} onChange={setFilters} onClear={clearFilters} />

      <Card>
        <CardContent>
          {tasks.isPending ? (
            <div className="grid gap-2" aria-label="Loading tasks">
              <Skeleton className="h-9 w-full" />
              <Skeleton className="h-9 w-full" />
              <Skeleton className="h-9 w-full" />
            </div>
          ) : tasks.isError ? (
            <ErrorState title="Could not load the tasks" description={errorMessage(tasks.error)}>
              <Button variant="outline" onClick={() => void tasks.refetch()}>
                Retry
              </Button>
            </ErrorState>
          ) : tasks.data.data.length === 0 ? (
            hasActiveFilters(filters) ? (
              <EmptyState title="No tasks match these filters" description="Try another filter, or clear them to see every task.">
                <Button variant="outline" onClick={clearFilters}>
                  Clear filters
                </Button>
              </EmptyState>
            ) : (
              <EmptyState
                title="No tasks yet"
                description={
                  canCreate
                    ? 'Create the first one: give it a title, and assign it if you like.'
                    : 'Tasks created in this organization will show here.'
                }
              >
                {canCreate && (
                  <TaskFormDialog>
                    <Button>
                      <PlusIcon aria-hidden />
                      New task
                    </Button>
                  </TaskFormDialog>
                )}
              </EmptyState>
            )
          ) : (
            // Dimmed while the next page or filter loads (the old rows stay meanwhile).
            <div className={cn('transition-opacity', tasks.isPlaceholderData && 'opacity-60')} aria-busy={tasks.isPlaceholderData}>
              <TasksTable tasks={tasks.data.data} />
            </div>
          )}
        </CardContent>
      </Card>

      {meta && meta.last_page > 1 && (
        <Pagination>
          <PaginationContent className="gap-2">
            <PaginationItem>
              <PageLink filters={filters} page={meta.current_page - 1} disabled={meta.current_page <= 1}>
                <ChevronLeftIcon aria-hidden />
                Previous
              </PageLink>
            </PaginationItem>
            <PaginationItem className="px-2 text-sm text-muted-foreground" aria-live="polite">
              Page {meta.current_page} of {meta.last_page}
            </PaginationItem>
            <PaginationItem>
              <PageLink filters={filters} page={meta.current_page + 1} disabled={meta.current_page >= meta.last_page}>
                Next
                <ChevronRightIcon aria-hidden />
              </PageLink>
            </PaginationItem>
          </PaginationContent>
        </Pagination>
      )}
    </div>
  )
}

function pageLink(filters: Filters, page: number): string {
  const query = filtersToSearchParams({ ...filters, page }).toString()
  return query ? `/tasks?${query}` : '/tasks'
}

/** A real link (a new history entry, so Back returns to the previous page), or a disabled button. */
function PageLink({ filters, page, disabled, children }: { filters: Filters; page: number; disabled: boolean; children: ReactNode }) {
  if (disabled) {
    return (
      <Button variant="ghost" size="sm" disabled>
        {children}
      </Button>
    )
  }

  return (
    <Button variant="ghost" size="sm" asChild>
      <Link to={pageLink(filters, page)}>{children}</Link>
    </Button>
  )
}

function EmptyState({ title, description, children }: { title: string; description: string; children?: ReactNode }) {
  return (
    <div className="flex flex-col items-center gap-3 px-4 py-12 text-center">
      <ListTodoIcon className="size-8 text-muted-foreground" aria-hidden />
      <h2 className="text-lg font-semibold">{title}</h2>
      <p className="max-w-sm text-sm text-muted-foreground">{description}</p>
      {children ? <div className="mt-2">{children}</div> : null}
    </div>
  )
}
