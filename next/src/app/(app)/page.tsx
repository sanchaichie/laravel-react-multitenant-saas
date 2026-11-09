import type { Metadata } from 'next'
import Link from 'next/link'
import type { ReactNode } from 'react'
import type { TaskFilters } from '@shared/types/api'
import { Card } from '@/components/Card'
import { EmptyState } from '@/components/EmptyState'
import { NoOrganization } from '@/components/NoOrganization'
import { PageHeader } from '@/components/PageHeader'
import { buttonVariants } from '@/components/ui/button'
import { tasksHref } from '@/features/tasks/filters'
import { TaskCharts } from '@/features/overview/TaskCharts'
import { TasksTable } from '@/features/tasks/TasksTable'
import { AlertIcon, CheckCircleIcon, ClockIcon, ListIcon, PlusIcon, TaskIcon, UserIcon } from '@/icons'
import { requireUser } from '@/lib/dal'
import { countTasks, getTasks } from '@/lib/tasks'

export const metadata: Metadata = { title: 'Overview' }

/**
 * The Tasks overview: counts and charts for the current organization. Each count
 * is GET /api/tasks with a filter (its meta.total), all fetched in parallel on the
 * server, so the page arrives complete: no loading spinners per card.
 */
export default async function OverviewPage() {
  const user = await requireUser()
  const organization = user.current_organization
  if (!organization) {
    return <NoOrganization />
  }

  const [todo, inProgress, done, overdue, mine, high, medium, low, overdueTasks] = await Promise.all([
    countTasks({ status: 'todo' }),
    countTasks({ status: 'in_progress' }),
    countTasks({ status: 'done' }),
    countTasks({ overdue: true }),
    countTasks({ assignee: 'me' }),
    countTasks({ priority: 'high' }),
    countTasks({ priority: 'medium' }),
    countTasks({ priority: 'low' }),
    getTasks({ overdue: true, sort: 'due' }),
  ])
  const total = todo + inProgress + done
  const canCreate = organization.permissions.includes('tasks.create')

  return (
    <>
      <PageHeader title="Overview" description={`How things stand in ${organization.name}.`}>
        {canCreate && (
          <Link href="/tasks/new" className={buttonVariants()}>
            <PlusIcon aria-hidden />
            New task
          </Link>
        )}
      </PageHeader>

      {total === 0 ? (
        <Card>
          <EmptyState
            icon={<ListIcon />}
            title="No tasks yet"
            description={canCreate ? 'Create the first task, and this page fills in.' : 'Tasks created in this organization will show here.'}
          />
        </Card>
      ) : (
        <div className="grid gap-4 md:gap-6">
          <div className="grid grid-cols-2 gap-4 md:gap-6 xl:grid-cols-5">
            <Metric label="To do" value={todo} filters={{ status: 'todo' }} icon={<TaskIcon />} />
            <Metric label="In progress" value={inProgress} filters={{ status: 'in_progress' }} icon={<ClockIcon />} />
            <Metric label="Done" value={done} filters={{ status: 'done' }} icon={<CheckCircleIcon />} />
            <Metric label="Overdue" value={overdue} filters={{ overdue: true }} icon={<AlertIcon />} tone={overdue > 0 ? 'error' : undefined} />
            <Metric label="Assigned to me" value={mine} filters={{ assignee: 'me' }} icon={<UserIcon />} />
          </div>

          <TaskCharts status={{ todo, inProgress, done }} priority={{ high, medium, low }} />

          <Card>
            <div className="flex flex-wrap items-center justify-between gap-2 px-5 pt-5 sm:px-6">
              <h2 className="text-base font-medium text-gray-800 dark:text-white/90">Overdue</h2>
              {overdue > 0 && (
                <Link href={tasksHref({ overdue: true, sort: 'due' })} className="text-sm text-brand-500 hover:text-brand-600 dark:text-brand-400">
                  See all {overdue}
                </Link>
              )}
            </div>
            {overdueTasks.data.length === 0 ? (
              <EmptyState icon={<CheckCircleIcon />} title="Nothing overdue" description="Every task with a due date is on time." />
            ) : (
              <div className="p-2 sm:p-3">
                <TasksTable tasks={overdueTasks.data.slice(0, 5)} />
              </div>
            )}
          </Card>
        </div>
      )}
    </>
  )
}

interface MetricProps {
  label: string
  value: number
  filters: TaskFilters
  icon: ReactNode
  tone?: 'error'
}

/** TailAdmin's metric card, linking to the list with that filter. */
function Metric({ label, value, filters, icon, tone }: MetricProps) {
  return (
    <Link
      href={tasksHref(filters)}
      className="rounded-2xl border border-gray-200 bg-white p-5 transition-colors hover:border-brand-300 md:p-6 dark:border-gray-800 dark:bg-white/3 dark:hover:border-brand-500/40"
    >
      <span
        className={
          tone === 'error'
            ? 'flex size-12 items-center justify-center rounded-xl bg-red-50 text-red-500 dark:bg-red-500/15 [&_svg]:size-6'
            : 'flex size-12 items-center justify-center rounded-xl bg-gray-100 text-gray-800 dark:bg-gray-800 dark:text-white/90 [&_svg]:size-6'
        }
        aria-hidden
      >
        {icon}
      </span>
      <span className="mt-5 block text-sm text-gray-500 dark:text-gray-400">{label}</span>
      <span className="mt-2 block text-title-sm font-bold text-gray-800 dark:text-white/90">{value}</span>
    </Link>
  )
}
