'use server'

import { revalidatePath } from 'next/cache'
import { redirect } from 'next/navigation'
import type { Task, TaskStatus } from '@shared/types/api'
import { issuesByField } from '@/features/auth/schemas'
import { text } from '@/features/auth/state'
import { serverApi } from '@/lib/api'
import { ApiError, errorMessage, fieldErrors } from '@/lib/errors'
import { TASK_STATUSES } from './filters'
import { changedFields, TASK_FIELDS, taskFormSchema, toPayload, type TaskFormValues } from './schema'

export interface TaskFormState {
  errors?: Record<string, string>
  message?: string
  values?: TaskFormValues
  /** Another tab switched organization: the page reloads for the new one. */
  organizationChanged?: boolean
}

export interface ActionResult {
  ok: boolean
  message?: string
  organizationChanged?: boolean
}

/** Every task action sends the organization the page showed (X-Organization-Id): 409 if it changed. */
function isOrganizationChanged(error: unknown): boolean {
  return error instanceof ApiError && error.status === 409 && error.data?.code === 'organization_changed'
}

function readForm(formData: FormData): TaskFormValues {
  return {
    title: text(formData, 'title'),
    description: text(formData, 'description'),
    status: text(formData, 'status') as TaskFormValues['status'],
    priority: text(formData, 'priority') as TaskFormValues['priority'],
    assignee_id: text(formData, 'assignee_id'),
    due_date: text(formData, 'due_date'),
  }
}

function failure(error: unknown, values: TaskFormValues): TaskFormState {
  if (isOrganizationChanged(error)) {
    return { organizationChanged: true, message: 'Your organization changed in another tab.', values }
  }
  const errors = fieldErrors(error, TASK_FIELDS)
  return Object.keys(errors).length > 0 ? { errors, values } : { message: errorMessage(error), values }
}

/** After any change: drop the cached pages that list tasks (the overview counts them too). */
function revalidateTasks() {
  revalidatePath('/', 'layout')
}

// `organizationId` (and the task) come first: the form binds them with .bind(),
// and useActionState passes (previous state, form data) after them.

export async function createTask(organizationId: number, _previous: TaskFormState, formData: FormData): Promise<TaskFormState> {
  const values = readForm(formData)
  const parsed = taskFormSchema.safeParse(values)
  if (!parsed.success) {
    return { errors: issuesByField(parsed.error), values }
  }

  let task: Task
  try {
    task = (
      await serverApi<{ data: Task }>('/api/tasks', { method: 'POST', body: toPayload(parsed.data), organizationId })
    ).data
  } catch (error) {
    return failure(error, values)
  }

  revalidateTasks()
  redirect(`/tasks/${task.id}`)
}

export async function updateTask(
  organizationId: number,
  taskId: number,
  before: TaskFormValues,
  _previous: TaskFormState,
  formData: FormData,
): Promise<TaskFormState> {
  const values = readForm(formData)
  const parsed = taskFormSchema.safeParse(values)
  if (!parsed.success) {
    return { errors: issuesByField(parsed.error), values }
  }

  const changes = changedFields(before, parsed.data)
  if (Object.keys(changes).length > 0) {
    try {
      await serverApi<{ data: Task }>(`/api/tasks/${taskId}`, { method: 'PATCH', body: changes, organizationId })
    } catch (error) {
      return failure(error, values)
    }
    revalidateTasks()
  }

  redirect(`/tasks/${taskId}`)
}

export async function setTaskStatus(organizationId: number, taskId: number, status: TaskStatus): Promise<ActionResult> {
  if (!TASK_STATUSES.includes(status)) {
    return { ok: false, message: 'Unknown status.' }
  }
  try {
    await serverApi<{ data: Task }>(`/api/tasks/${taskId}`, { method: 'PATCH', body: { status }, organizationId })
  } catch (error) {
    return { ok: false, message: errorMessage(error), organizationChanged: isOrganizationChanged(error) }
  }

  revalidateTasks()
  return { ok: true }
}

export async function deleteTask(organizationId: number, taskId: number): Promise<ActionResult> {
  try {
    await serverApi(`/api/tasks/${taskId}`, { method: 'DELETE', organizationId })
  } catch (error) {
    return { ok: false, message: errorMessage(error), organizationChanged: isOrganizationChanged(error) }
  }

  revalidateTasks()
  redirect('/tasks')
}
