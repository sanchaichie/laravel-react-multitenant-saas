import { z } from 'zod'
import type { Task, TaskPayload } from '@shared/types/api'

export const UNASSIGNED = 'none'

// The form's fields as the browser posts them (all strings), checked like web's TaskForm.
export const taskFormSchema = z.object({
  title: z.string().trim().min(1, 'Enter a title.').max(255, 'At most 255 characters.'),
  description: z.string().max(5000, 'At most 5000 characters.'),
  status: z.enum(['todo', 'in_progress', 'done'], { error: 'Choose a status.' }),
  priority: z.enum(['low', 'medium', 'high'], { error: 'Choose a priority.' }),
  // A member's id as a string, or UNASSIGNED.
  assignee_id: z.string().regex(/^(none|[1-9][0-9]{0,17})$/, 'Choose an assignee.'),
  // What a native date input gives: '' or 'YYYY-MM-DD'.
  due_date: z.union([z.literal(''), z.iso.date({ error: 'Enter a valid date.' })]),
})

export type TaskFormValues = z.infer<typeof taskFormSchema>

export const TASK_FIELDS = ['title', 'description', 'status', 'priority', 'assignee_id', 'due_date'] as const

export function toValues(task?: Task): TaskFormValues {
  return {
    title: task?.title ?? '',
    description: task?.description ?? '',
    status: task?.status ?? 'todo',
    priority: task?.priority ?? 'medium',
    assignee_id: task?.assignee ? String(task.assignee.id) : UNASSIGNED,
    due_date: task?.due_date ?? '',
  }
}

export function toPayload(values: TaskFormValues): TaskPayload {
  return {
    title: values.title.trim(),
    description: values.description.trim() || null,
    status: values.status,
    priority: values.priority,
    assignee_id: values.assignee_id === UNASSIGNED ? null : Number(values.assignee_id),
    due_date: values.due_date || null,
  }
}

/**
 * Only the fields changed in the form, compared with the task as the form opened:
 * a field someone else changed meanwhile isn't overwritten with this form's older value.
 */
export function changedFields(before: TaskFormValues, after: TaskFormValues): Partial<TaskPayload> {
  const old = toPayload(before)
  return Object.fromEntries(
    Object.entries(toPayload(after)).filter(([field, value]) => old[field as keyof TaskPayload] !== value),
  ) as Partial<TaskPayload>
}
