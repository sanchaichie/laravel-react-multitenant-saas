import { zodResolver } from '@hookform/resolvers/zod'
import { Controller, useForm } from 'react-hook-form'
import { toast } from 'sonner'
import { z } from 'zod'
import { Button } from '@/components/ui/button'
import { Field, FieldError, FieldGroup, FieldLabel } from '@/components/ui/field'
import { Input } from '@/components/ui/input'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Spinner } from '@/components/ui/spinner'
import { Textarea } from '@/components/ui/textarea'
import { useMembers } from '@/features/members/api'
import { applyFieldErrors, errorMessage } from '@/lib/apiErrors'
import { describedBy } from '@/lib/aria'
import type { Task, TaskPayload } from '@/types/api'
import { useCreateTask, useUpdateTask } from './api'
import { TASK_PRIORITIES, TASK_STATUSES, priorityLabel, statusLabel } from './filters'

const UNASSIGNED = 'none'

const schema = z.object({
  title: z.string().trim().min(1, 'Enter a title.').max(255, 'At most 255 characters.'),
  description: z.string().max(5000, 'At most 5000 characters.'),
  status: z.enum(['todo', 'in_progress', 'done']),
  priority: z.enum(['low', 'medium', 'high']),
  // A member's id as a string, or UNASSIGNED (a Radix Select's values are strings).
  assignee_id: z.string(),
  // What a native date input gives: '' or 'YYYY-MM-DD'.
  due_date: z.union([z.literal(''), z.iso.date({ error: 'Enter a valid date.' })]),
})

type Values = z.infer<typeof schema>

const SERVER_FIELDS = ['title', 'description', 'status', 'priority', 'assignee_id', 'due_date'] as const

function toValues(task?: Task): Values {
  return {
    title: task?.title ?? '',
    description: task?.description ?? '',
    status: task?.status ?? 'todo',
    priority: task?.priority ?? 'medium',
    assignee_id: task?.assignee ? String(task.assignee.id) : UNASSIGNED,
    due_date: task?.due_date ?? '',
  }
}

function toPayload(values: Values): TaskPayload {
  return {
    title: values.title.trim(),
    description: values.description.trim() || null,
    status: values.status,
    priority: values.priority,
    assignee_id: values.assignee_id === UNASSIGNED ? null : Number(values.assignee_id),
    due_date: values.due_date || null,
  }
}

interface TaskFormProps {
  /** The task to edit; none to create one. */
  task?: Task
  onSaved: (task: Task) => void
  onCancel: () => void
}

/**
 * Create or edit a task: one form, filled from `task` through defaultValues (the
 * initial reactive state of a Vue form). The caller mounts a fresh one per task.
 */
export function TaskForm({ task, onSaved, onCancel }: TaskFormProps) {
  const createTask = useCreateTask()
  const updateTask = useUpdateTask()
  const saving = createTask.isPending || updateTask.isPending
  const members = useMembers()

  const form = useForm<Values>({ resolver: zodResolver(schema), defaultValues: toValues(task) })
  const { errors } = form.formState

  // The current assignee stays choosable even if they're not in the list (yet).
  const assignees = members.data?.data ?? []
  const keepsMissingAssignee = task?.assignee && !assignees.some((member) => member.id === task.assignee?.id)

  function onSubmit(values: Values) {
    const callbacks = {
      onSuccess: ({ data }: { data: Task }) => {
        toast.success(task ? 'Task saved' : 'Task created')
        onSaved(data)
      },
      onError: (error: Error) => {
        if (!applyFieldErrors(error, form.setError, SERVER_FIELDS)) {
          toast.error(task ? 'Could not save the task' : 'Could not create the task', { description: errorMessage(error) })
        }
      },
    }

    if (task) {
      // Only what was changed here, compared with the task the form opened with: a
      // field someone else changed meanwhile isn't overwritten with this form's
      // (older) value.
      const before = toPayload(toValues(task))
      const changed = Object.fromEntries(
        Object.entries(toPayload(values)).filter(([field, value]) => before[field as keyof TaskPayload] !== value),
      ) as Partial<TaskPayload>
      if (Object.keys(changed).length === 0) {
        onSaved(task)
        return
      }
      updateTask.mutate({ id: task.id, ...changed }, callbacks)
    } else {
      createTask.mutate(toPayload(values), callbacks)
    }
  }

  return (
    <form onSubmit={form.handleSubmit(onSubmit)} noValidate>
      <FieldGroup>
        <Field data-invalid={!!errors.title}>
          <FieldLabel htmlFor="task-title">Title</FieldLabel>
          <Input
            id="task-title"
            autoFocus
            autoComplete="off"
            aria-invalid={!!errors.title}
            aria-describedby={describedBy(errors.title && 'task-title-error')}
            {...form.register('title')}
          />
          <FieldError id="task-title-error" errors={[errors.title]} />
        </Field>

        <Field data-invalid={!!errors.description}>
          <FieldLabel htmlFor="task-description">Description</FieldLabel>
          <Textarea
            id="task-description"
            rows={4}
            aria-invalid={!!errors.description}
            aria-describedby={describedBy(errors.description && 'task-description-error')}
            {...form.register('description')}
          />
          <FieldError id="task-description-error" errors={[errors.description]} />
        </Field>

        <div className="grid gap-4 sm:grid-cols-2">
          <Field data-invalid={!!errors.status}>
            <FieldLabel htmlFor="task-status">Status</FieldLabel>
            {/* Radix selects aren't native inputs: Controller hands them value/onChange. */}
            <Controller
              control={form.control}
              name="status"
              render={({ field }) => (
                <Select value={field.value} onValueChange={field.onChange}>
                  <SelectTrigger id="task-status" className="w-full" aria-invalid={!!errors.status}>
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
              )}
            />
            <FieldError errors={[errors.status]} />
          </Field>

          <Field data-invalid={!!errors.priority}>
            <FieldLabel htmlFor="task-priority">Priority</FieldLabel>
            <Controller
              control={form.control}
              name="priority"
              render={({ field }) => (
                <Select value={field.value} onValueChange={field.onChange}>
                  <SelectTrigger id="task-priority" className="w-full" aria-invalid={!!errors.priority}>
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    {TASK_PRIORITIES.map((priority) => (
                      <SelectItem key={priority} value={priority}>
                        {priorityLabel(priority)}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              )}
            />
            <FieldError errors={[errors.priority]} />
          </Field>

          <Field data-invalid={!!errors.assignee_id}>
            <FieldLabel htmlFor="task-assignee">Assignee</FieldLabel>
            <Controller
              control={form.control}
              name="assignee_id"
              render={({ field }) => (
                <Select value={field.value} onValueChange={field.onChange}>
                  <SelectTrigger
                    id="task-assignee"
                    className="w-full"
                    aria-invalid={!!errors.assignee_id}
                    aria-describedby={describedBy(errors.assignee_id && 'task-assignee-error')}
                  >
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value={UNASSIGNED}>Unassigned</SelectItem>
                    {keepsMissingAssignee && task.assignee && (
                      <SelectItem value={String(task.assignee.id)}>{task.assignee.name}</SelectItem>
                    )}
                    {assignees.map((member) => (
                      <SelectItem key={member.id} value={String(member.id)}>
                        {member.is_me ? `${member.name} (you)` : member.name}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              )}
            />
            <FieldError id="task-assignee-error" errors={[errors.assignee_id]} />
          </Field>

          <Field data-invalid={!!errors.due_date}>
            <FieldLabel htmlFor="task-due-date">Due date</FieldLabel>
            {/* A native input: register() wires it like any text field (no Controller). */}
            <Input
              id="task-due-date"
              type="date"
              aria-invalid={!!errors.due_date}
              aria-describedby={describedBy(errors.due_date && 'task-due-date-error')}
              {...form.register('due_date')}
            />
            <FieldError id="task-due-date-error" errors={[errors.due_date]} />
          </Field>
        </div>

        <div className="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <Button type="button" variant="outline" onClick={onCancel}>
            Cancel
          </Button>
          <Button type="submit" disabled={saving}>
            {saving && <Spinner />}
            {task ? 'Save' : 'Create task'}
          </Button>
        </div>
      </FieldGroup>
    </form>
  )
}
