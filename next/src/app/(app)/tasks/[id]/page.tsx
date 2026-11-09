import type { Metadata } from 'next'
import Link from 'next/link'
import { notFound } from 'next/navigation'
import { Card } from '@/components/Card'
import { NoOrganization } from '@/components/NoOrganization'
import { PageHeader } from '@/components/PageHeader'
import { buttonVariants } from '@/components/ui/button'
import { parseTaskId } from '@/features/tasks/filters'
import { DeleteTaskButton, StatusSelect } from '@/features/tasks/TaskActions'
import { OverdueBadge, TaskPriorityBadge, TaskStatusBadge } from '@/features/tasks/TaskBadges'
import { ArrowLeftIcon, PencilIcon } from '@/icons'
import { requireUser } from '@/lib/dal'
import { formatDate } from '@/lib/format'
import { getTask } from '@/lib/tasks'

// generateMetadata runs with the page's params; getTask is cache()d, so the page's
// own call reuses this request.
export async function generateMetadata({ params }: PageProps<'/tasks/[id]'>): Promise<Metadata> {
  const id = parseTaskId((await params).id)
  const task = id === null ? null : await getTask(id).catch(() => null)

  return { title: task?.title ?? 'Task not found' }
}

export default async function TaskPage({ params }: PageProps<'/tasks/[id]'>) {
  const { id: rawId } = await params
  const user = await requireUser()
  const organization = user.current_organization
  if (!organization) {
    return <NoOrganization />
  }

  const id = parseTaskId(rawId)
  // Not found, or another organization's task: the API answers 404 for both.
  const task = id === null ? null : await getTask(id)
  if (!task) {
    notFound()
  }

  const dt = 'text-gray-500 dark:text-gray-400'

  return (
    <>
      <Link href="/tasks" className={`${buttonVariants({ variant: 'ghost', size: 'sm' })} -ms-3 mb-2`}>
        <ArrowLeftIcon aria-hidden className="size-4" />
        Tasks
      </Link>
      <PageHeader title={task.title}>
        {task.can.update && (
          <Link href={`/tasks/${task.id}/edit`} className={buttonVariants({ variant: 'outline' })}>
            <PencilIcon aria-hidden />
            Edit
          </Link>
        )}
        {task.can.delete && <DeleteTaskButton organizationId={organization.id} task={task} />}
      </PageHeader>

      <div className="grid items-start gap-6 lg:grid-cols-[1fr_20rem]">
        <Card className="min-w-0 p-5 sm:p-6">
          <h2 className="mb-3 text-base font-medium text-gray-800 dark:text-white/90">Description</h2>
          {task.content_hidden ? (
            // A platform admin viewing as the user: the API sends no description.
            <p className="text-sm text-gray-500">Content hidden in the support view.</p>
          ) : task.description ? (
            // Plain text, line breaks kept: user content is never rendered as HTML.
            <p className="text-sm break-words whitespace-pre-wrap text-gray-700 dark:text-gray-300">{task.description}</p>
          ) : (
            <p className="text-sm text-gray-500 dark:text-gray-400">No description.</p>
          )}
        </Card>

        <Card className="p-5 sm:p-6">
          <dl className="grid grid-cols-[auto_1fr] items-center gap-x-4 gap-y-3 text-sm text-gray-700 dark:text-gray-300">
            <dt className={dt}>Status</dt>
            <dd>{task.can.update ? <StatusSelect organizationId={organization.id} task={task} /> : <TaskStatusBadge status={task.status} />}</dd>
            <dt className={dt}>Priority</dt>
            <dd>
              <TaskPriorityBadge priority={task.priority} />
            </dd>
            <dt className={dt}>Assignee</dt>
            <dd>{task.assignee?.name ?? <span className="text-gray-400">Unassigned</span>}</dd>
            <dt className={dt}>Due</dt>
            <dd className="flex flex-wrap items-center gap-2">
              {task.due_date ? formatDate(task.due_date) : <span className="text-gray-400">No due date</span>}
              {task.is_overdue && <OverdueBadge />}
            </dd>
            <dt className={dt}>Created by</dt>
            <dd>{task.creator?.name ?? <span className="text-gray-400">Deleted user</span>}</dd>
            <dt className={dt}>Created</dt>
            <dd>{formatDate(task.created_at)}</dd>
            <dt className={dt}>Updated</dt>
            <dd>{formatDate(task.updated_at)}</dd>
          </dl>
        </Card>
      </div>
    </>
  )
}
