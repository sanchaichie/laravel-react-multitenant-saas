import { useState } from 'react'
import { ArrowLeftIcon, PencilIcon, Trash2Icon } from 'lucide-react'
import { Link, Navigate, useNavigate, useParams } from 'react-router'
import { toast } from 'sonner'
import { ConfirmDialog } from '@/components/ConfirmDialog'
import { ErrorState } from '@/components/ErrorState'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Skeleton } from '@/components/ui/skeleton'
import { useDeleteTask, useTask, useUpdateTask } from '@/features/tasks/api'
import { TASK_STATUSES, statusLabel } from '@/features/tasks/filters'
import { OverdueBadge, TaskPriorityBadge, TaskStatusBadge } from '@/features/tasks/TaskBadges'
import { TaskFormDialog } from '@/features/tasks/TaskFormDialog'
import { ApiError } from '@/lib/api'
import { errorMessage } from '@/lib/apiErrors'
import { formatDate } from '@/lib/format'
import { useCurrentOrganization } from '@/stores/auth'
import type { Task, TaskStatus } from '@/types/api'

/** A task id as the API accepts it (up to 18 digits); anything else is "not found" without a request. */
function parseTaskId(value: string | undefined): number | null {
  return value && /^[1-9][0-9]{0,17}$/.test(value) ? Number(value) : null
}

export function TaskDetailPage() {
  const taskId = parseTaskId(useParams().id)
  const organizationId = useCurrentOrganization()?.id

  // The task belongs to the organization this page opened in. After a switch,
  // go back to the list instead of asking the new organization for it (a 404).
  // useState's initial value is read once, on mount, so it remembers that organization.
  const [openedIn] = useState(organizationId)
  const switched = organizationId !== openedIn

  const task = useTask(switched ? null : taskId)

  if (switched) {
    return <Navigate to="/tasks" replace />
  }

  const notFound = taskId === null || (task.error instanceof ApiError && task.error.status === 404)
  if (notFound) {
    return (
      <>
        <title>Task not found · React SaaS</title>
        <ErrorState title="Task not found" description="It may have been deleted, or it belongs to another organization.">
          <Button variant="outline" asChild>
            <Link to="/tasks">Back to tasks</Link>
          </Button>
        </ErrorState>
      </>
    )
  }

  if (task.isPending) {
    return (
      <div className="grid gap-4" aria-label="Loading task">
        <Skeleton className="h-8 w-2/3" />
        <Skeleton className="h-40 w-full" />
      </div>
    )
  }

  if (task.isError) {
    return (
      <ErrorState title="Could not load the task" description={errorMessage(task.error)}>
        <Button variant="outline" onClick={() => void task.refetch()}>
          Retry
        </Button>
      </ErrorState>
    )
  }

  return <TaskDetails task={task.data.data} />
}

function TaskDetails({ task }: { task: Task }) {
  const navigate = useNavigate()
  const deleteTask = useDeleteTask()

  function onDelete() {
    deleteTask.mutate(task, {
      onSuccess: () => {
        toast.success('Task deleted', { description: task.title })
        void navigate('/tasks', { replace: true })
      },
      onError: (error) => toast.error('Could not delete the task', { description: errorMessage(error) }),
    })
  }

  return (
    <div className="grid gap-6">
      <title>{`${task.title} · React SaaS`}</title>
      <div>
        <Button variant="ghost" size="sm" className="-ml-2" asChild>
          <Link to="/tasks">
            <ArrowLeftIcon aria-hidden />
            Tasks
          </Link>
        </Button>
      </div>

      <div className="flex flex-wrap items-start justify-between gap-3">
        <h1 className="min-w-0 text-2xl font-semibold break-words">{task.title}</h1>
        {(task.can.update || task.can.delete) && (
          <div className="flex gap-2">
            {task.can.update && (
              <TaskFormDialog task={task}>
                <Button variant="outline">
                  <PencilIcon aria-hidden />
                  Edit
                </Button>
              </TaskFormDialog>
            )}
            {task.can.delete && (
              <ConfirmDialog
                title="Delete this task?"
                description={
                  <>
                    <span className="font-medium text-foreground">{task.title}</span> will be deleted for everyone. This
                    can't be undone.
                  </>
                }
                confirmLabel="Delete"
                onConfirm={onDelete}
              >
                <Button variant="outline" disabled={deleteTask.isPending}>
                  <Trash2Icon aria-hidden />
                  Delete
                </Button>
              </ConfirmDialog>
            )}
          </div>
        )}
      </div>

      <div className="grid gap-6 lg:grid-cols-[1fr_18rem]">
        <Card>
          <CardHeader>
            <CardTitle>
              <h2>Description</h2>
            </CardTitle>
          </CardHeader>
          <CardContent>
            {task.description ? (
              // Plain text, line breaks kept: user content is never rendered as HTML.
              <p className="text-sm break-words whitespace-pre-wrap">{task.description}</p>
            ) : (
              <p className="text-sm text-muted-foreground">No description.</p>
            )}
          </CardContent>
        </Card>

        <Card>
          <CardContent>
            <dl className="grid grid-cols-[auto_1fr] items-center gap-x-4 gap-y-3 text-sm">
              <dt className="text-muted-foreground">Status</dt>
              <dd>{task.can.update ? <StatusSelect task={task} /> : <TaskStatusBadge status={task.status} />}</dd>
              <dt className="text-muted-foreground">Priority</dt>
              <dd>
                <TaskPriorityBadge priority={task.priority} />
              </dd>
              <dt className="text-muted-foreground">Assignee</dt>
              <dd>{task.assignee?.name ?? <span className="text-muted-foreground">Unassigned</span>}</dd>
              <dt className="text-muted-foreground">Due</dt>
              <dd className="flex flex-wrap items-center gap-2">
                {task.due_date ? formatDate(task.due_date) : <span className="text-muted-foreground">No due date</span>}
                {task.is_overdue && <OverdueBadge />}
              </dd>
              <dt className="text-muted-foreground">Created by</dt>
              <dd>{task.creator?.name ?? <span className="text-muted-foreground">Deleted user</span>}</dd>
              <dt className="text-muted-foreground">Created</dt>
              <dd>{formatDate(task.created_at)}</dd>
              <dt className="text-muted-foreground">Updated</dt>
              <dd>{formatDate(task.updated_at)}</dd>
            </dl>
          </CardContent>
        </Card>
      </div>
    </div>
  )
}

/** Changes the status at once. Controlled by the server's value: it moves when the save succeeds. */
function StatusSelect({ task }: { task: Task }) {
  const updateTask = useUpdateTask()

  function onChange(status: string) {
    updateTask.mutate(
      { id: task.id, status: status as TaskStatus },
      {
        onSuccess: ({ data }) => toast.success(`Marked ${statusLabel(data.status).toLowerCase()}`),
        onError: (error) => toast.error('Could not change the status', { description: errorMessage(error) }),
      },
    )
  }

  return (
    <Select value={task.status} onValueChange={onChange} disabled={updateTask.isPending}>
      <SelectTrigger aria-label="Status" size="sm" className="w-36">
        <SelectValue />
      </SelectTrigger>
      <SelectContent>
        {TASK_STATUSES.map((status) => (
          <SelectItem key={status} value={status}>
            {statusLabel(status)}
          </SelectItem>
        ))}
      </SelectContent>
    </Select>
  )
}
