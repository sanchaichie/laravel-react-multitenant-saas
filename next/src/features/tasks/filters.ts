import type { TaskFilters, TaskPriority, TaskSort, TaskStatus } from '@shared/types/api'

// The same rules as web/src/features/tasks/filters.ts, used on the server (the
// page's searchParams) and in the browser (the filter bar).

export const TASK_STATUSES: TaskStatus[] = ['todo', 'in_progress', 'done']
export const TASK_PRIORITIES: TaskPriority[] = ['high', 'medium', 'low']
export const TASK_SORTS: TaskSort[] = ['newest', 'due', 'priority']

const statusLabels: Record<TaskStatus, string> = { todo: 'To do', in_progress: 'In progress', done: 'Done' }
const priorityLabels: Record<TaskPriority, string> = { low: 'Low', medium: 'Medium', high: 'High' }
const sortLabels: Record<TaskSort, string> = { newest: 'Newest first', due: 'Due date', priority: 'Priority' }

export const statusLabel = (status: TaskStatus) => statusLabels[status]
export const priorityLabel = (priority: TaskPriority) => priorityLabels[priority]
export const sortLabel = (sort: TaskSort) => sortLabels[sort]

function oneOf<T extends string>(values: readonly T[], value: string | null): T | undefined {
  return values.find((candidate) => candidate === value)
}

/** A page's `searchParams` (values may repeat) as URLSearchParams, first value of each. */
export function toURLSearchParams(params: Record<string, string | string[] | undefined>): URLSearchParams {
  const result = new URLSearchParams()
  for (const [key, value] of Object.entries(params)) {
    const first = Array.isArray(value) ? value[0] : value
    if (first !== undefined) {
      result.set(key, first)
    }
  }

  return result
}

/**
 * The filters in a URL's query string. Anything unknown or malformed is dropped
 * (a hand-edited URL shows the full list instead of an API error).
 */
export function parseFilters(params: URLSearchParams): TaskFilters {
  const assignee = params.get('assignee')
  const page = Number(params.get('page'))
  const search = params.get('search')?.trim().slice(0, 100)

  const filters: TaskFilters = {
    status: oneOf(TASK_STATUSES, params.get('status')),
    priority: oneOf(TASK_PRIORITIES, params.get('priority')),
    assignee: assignee && /^(me|none|[1-9][0-9]{0,17})$/.test(assignee) ? assignee : undefined,
    overdue: params.get('overdue') === '1' || undefined,
    search: search || undefined,
    sort: oneOf(TASK_SORTS, params.get('sort')),
    page: Number.isInteger(page) && page > 1 ? page : undefined,
  }

  return Object.fromEntries(Object.entries(filters).filter(([, value]) => value !== undefined)) as TaskFilters
}

/** The other way: for the URL, and for GET /api/tasks (which takes the same names). */
export function filtersToSearchParams(filters: TaskFilters): URLSearchParams {
  const params = new URLSearchParams()
  if (filters.status) params.set('status', filters.status)
  if (filters.priority) params.set('priority', filters.priority)
  if (filters.assignee) params.set('assignee', filters.assignee)
  if (filters.overdue) params.set('overdue', '1')
  if (filters.search) params.set('search', filters.search)
  if (filters.sort && filters.sort !== 'newest') params.set('sort', filters.sort)
  if (filters.page && filters.page > 1) params.set('page', String(filters.page))

  return params
}

/** `/tasks` with these filters. */
export function tasksHref(filters: TaskFilters): string {
  const query = filtersToSearchParams(filters).toString()
  return query ? `/tasks?${query}` : '/tasks'
}

/** Whether anything narrows the list (sort and page don't). */
export function hasActiveFilters(filters: TaskFilters): boolean {
  return Boolean(filters.status || filters.priority || filters.assignee || filters.overdue || filters.search)
}

/** A task id as the API accepts it (up to 18 digits), or null: "not found" without a request. */
export function parseTaskId(value: string): number | null {
  return /^[1-9][0-9]{0,17}$/.test(value) ? Number(value) : null
}
