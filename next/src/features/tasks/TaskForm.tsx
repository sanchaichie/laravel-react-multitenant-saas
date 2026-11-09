'use client'

import { useActionState, useEffect } from 'react'
import Link from 'next/link'
import { useRouter } from 'next/navigation'
import { toast } from 'sonner'
import type { Member, Task } from '@shared/types/api'
import { FormMessage } from '@/components/FormMessage'
import { TextField } from '@/components/TextField'
import { Button, buttonVariants } from '@/components/ui/button'
import { Field, FieldError, FieldLabel } from '@/components/ui/field'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Spinner } from '@/components/ui/spinner'
import { Textarea } from '@/components/ui/textarea'
import { createTask, type TaskFormState, updateTask } from './actions'
import { TASK_PRIORITIES, TASK_STATUSES, priorityLabel, statusLabel } from './filters'
import { toValues, UNASSIGNED } from './schema'

interface TaskFormProps {
  organizationId: number
  members: Member[]
  /** The task to edit; none to create one. */
  task?: Task
}

/**
 * Create or edit a task. The form posts to a Server Action (createTask or
 * updateTask, with the organization and task bound in front with .bind()); on
 * success the action redirects to the task, otherwise its answer (field errors)
 * comes back through useActionState.
 */
export function TaskForm({ organizationId, members, task }: TaskFormProps) {
  const router = useRouter()
  const action = task ? updateTask.bind(null, organizationId, task.id, toValues(task)) : createTask.bind(null, organizationId)
  const [state, formAction, isPending] = useActionState<TaskFormState, FormData>(action, {})
  const values = state.values ?? toValues(task)

  // Another tab switched organization: this form belongs to the old one. Reload
  // the page on the server, which renders the new organization's (a refetch ↔ invalidateQueries).
  useEffect(() => {
    if (state.organizationChanged) {
      toast.info('Your organization changed', { description: 'It was switched in another tab.' })
      router.replace('/tasks')
      router.refresh()
    }
  }, [state.organizationChanged, router])

  // The current assignee stays choosable even if they're not in the list (they left).
  const keepsMissingAssignee = task?.assignee && !members.some((member) => member.id === task.assignee?.id)
  const assigneeItems = [
    { value: UNASSIGNED, label: 'Unassigned' },
    ...(keepsMissingAssignee && task.assignee ? [{ value: String(task.assignee.id), label: task.assignee.name }] : []),
    ...members.map((member) => ({ value: String(member.id), label: member.is_me ? `${member.name} (you)` : member.name })),
  ]
  const statusItems = TASK_STATUSES.map((value) => ({ value, label: statusLabel(value) }))
  const priorityItems = TASK_PRIORITIES.map((value) => ({ value, label: priorityLabel(value) }))

  return (
    // key: each answer remounts the fields with the values sent (React resets a form after its action).
    <form action={formAction} noValidate className="space-y-5" key={JSON.stringify(values)}>
      <FormMessage message={state.message} />
      <TextField name="title" label="Title" autoFocus autoComplete="off" defaultValue={values.title} error={state.errors?.title} />

      <Field data-invalid={Boolean(state.errors?.description)} controlId="task-description">
        <FieldLabel>Description</FieldLabel>
        <Textarea
          name="description"
          rows={5}
          defaultValue={values.description}
          aria-invalid={state.errors?.description ? true : undefined}
          aria-describedby={state.errors?.description ? 'task-description-error' : undefined}
        />
        {state.errors?.description && <FieldError id="task-description-error">{state.errors.description}</FieldError>}
      </Field>

      <div className="grid gap-5 sm:grid-cols-2">
        <SelectField name="status" label="Status" items={statusItems} defaultValue={values.status} error={state.errors?.status} />
        <SelectField
          name="priority"
          label="Priority"
          items={priorityItems}
          defaultValue={values.priority}
          error={state.errors?.priority}
        />
        <SelectField
          name="assignee_id"
          label="Assignee"
          items={assigneeItems}
          defaultValue={values.assignee_id}
          error={state.errors?.assignee_id}
        />
        <TextField name="due_date" label="Due date" type="date" defaultValue={values.due_date} error={state.errors?.due_date} />
      </div>

      <div className="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
        <Link href={task ? `/tasks/${task.id}` : '/tasks'} className={buttonVariants({ variant: 'outline' })}>
          Cancel
        </Link>
        <Button type="submit" disabled={isPending}>
          {isPending && <Spinner />}
          {task ? 'Save' : 'Create task'}
        </Button>
      </div>
    </form>
  )
}

interface SelectFieldProps {
  name: string
  label: string
  items: { value: string; label: string }[]
  defaultValue: string
  error?: string
}

/** A Base UI Select inside a posted form: `name` makes it send a hidden input, like a native <select>. */
function SelectField({ name, label, items, defaultValue, error }: SelectFieldProps) {
  const id = `task-${name}`

  return (
    <Field data-invalid={Boolean(error)} controlId={id}>
      <FieldLabel>{label}</FieldLabel>
      <Select name={name} items={items} defaultValue={defaultValue}>
        <SelectTrigger
          id={id}
          aria-invalid={error ? true : undefined}
          aria-describedby={error ? `${id}-error` : undefined}
        >
          <SelectValue />
        </SelectTrigger>
        <SelectContent>
          {items.map((item) => (
            <SelectItem key={item.value} value={item.value}>
              {item.label}
            </SelectItem>
          ))}
        </SelectContent>
      </Select>
      {error && <FieldError id={`${id}-error`}>{error}</FieldError>}
    </Field>
  )
}
