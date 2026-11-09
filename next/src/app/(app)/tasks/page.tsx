import type { Metadata } from 'next'
import Link from 'next/link'
import { redirect } from 'next/navigation'
import type { ReactNode } from 'react'
import type { TaskFilters as Filters } from '@shared/types/api'
import { Card } from '@/components/Card'
import { EmptyState } from '@/components/EmptyState'
import { NoOrganization } from '@/components/NoOrganization'
import { PageHeader } from '@/components/PageHeader'
import { buttonVariants } from '@/components/ui/button'
import { hasActiveFilters, parseFilters, tasksHref, toURLSearchParams } from '@/features/tasks/filters'
import { TaskFilters } from '@/features/tasks/TaskFilters'
import { TasksTable } from '@/features/tasks/TasksTable'
import { ChevronLeftIcon, ChevronRightIcon, ListIcon, PlusIcon } from '@/icons'
import { requireUser } from '@/lib/dal'
import { getMembers, getTasks } from '@/lib/tasks'
import { cn } from '@/utils'

export const metadata: Metadata = { title: 'Tasks' }

/**
 * A Server Component page: the filters come from the URL (the `searchParams`
 * prop ↔ web's useSearchParams), the list is fetched on the server and sent as
 * HTML. Changing a filter replaces the URL and Next.js renders this again.
 */
export default async function TasksPage({ searchParams }: PageProps<'/tasks'>) {
  const user = await requireUser()
  const organization = user.current_organization
  if (!organization) {
    return <NoOrganization />
  }

  const filters = parseFilters(toURLSearchParams(await searchParams))
  // Both requests at once, not one after the other.
  const [tasks, members] = await Promise.all([getTasks(filters), getMembers()])
  const { meta } = tasks

  // A page past the end (tasks deleted, or an old link): go to the last one.
  if ((filters.page ?? 1) > meta.last_page && meta.last_page >= 1) {
    redirect(tasksHref({ ...filters, page: meta.last_page }))
  }

  // A role without tasks.create (say, a viewer) only reads.
  const canCreate = organization.permissions.includes('tasks.create')
  const newTask = canCreate && (
    <Link href="/tasks/new" className={buttonVariants()}>
      <PlusIcon aria-hidden />
      New task
    </Link>
  )

  return (
    <>
      <PageHeader title="Tasks" description={`Everything to do in ${organization.name}.`}>
        {newTask}
      </PageHeader>

      <div className="grid gap-4">
        <TaskFilters filters={filters} members={members} />

        <Card>
          {tasks.data.length === 0 ? (
            hasActiveFilters(filters) ? (
              <EmptyState icon={<ListIcon />} title="No tasks match these filters" description="Try another filter, or clear them to see every task.">
                <Link href={tasksHref({ sort: filters.sort })} replace className={buttonVariants({ variant: 'outline' })}>
                  Clear filters
                </Link>
              </EmptyState>
            ) : (
              <EmptyState
                icon={<ListIcon />}
                title="No tasks yet"
                description={
                  canCreate
                    ? 'Create the first one: give it a title, and assign it if you like.'
                    : 'Tasks created in this organization will show here.'
                }
              >
                {newTask}
              </EmptyState>
            )
          ) : (
            <TasksTable tasks={tasks.data} />
          )}
        </Card>

        {meta.last_page > 1 && (
          <nav aria-label="Pagination" className="flex items-center justify-center gap-2">
            <PageLink filters={filters} page={meta.current_page - 1} disabled={meta.current_page <= 1}>
              <ChevronLeftIcon aria-hidden />
              Previous
            </PageLink>
            <span className="px-2 text-sm text-gray-500 dark:text-gray-400" aria-live="polite">
              Page {meta.current_page} of {meta.last_page}
            </span>
            <PageLink filters={filters} page={meta.current_page + 1} disabled={meta.current_page >= meta.last_page}>
              Next
              <ChevronRightIcon aria-hidden />
            </PageLink>
          </nav>
        )}
      </div>
    </>
  )
}

/** A real link (a new history entry, so Back returns to the previous page), or a disabled-looking span. */
function PageLink({ filters, page, disabled, children }: { filters: Filters; page: number; disabled: boolean; children: ReactNode }) {
  const className = buttonVariants({ variant: 'ghost', size: 'sm' })
  if (disabled) {
    return (
      <span aria-disabled className={cn(className, 'pointer-events-none opacity-50')}>
        {children}
      </span>
    )
  }

  return (
    <Link href={tasksHref({ ...filters, page })} className={className}>
      {children}
    </Link>
  )
}
