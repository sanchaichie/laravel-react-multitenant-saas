import 'server-only'
import { cache } from 'react'
import type { Member, Paginated, Task, TaskFilters } from '@shared/types/api'
import { filtersToSearchParams } from '@/features/tasks/filters'
import { serverApi } from './api'
import { ApiError } from './errors'

/** One page of the current organization's tasks (the API picks the organization). */
export function getTasks(filters: TaskFilters): Promise<Paginated<Task>> {
  const query = filtersToSearchParams(filters).toString()
  return serverApi<Paginated<Task>>(`/api/tasks${query ? `?${query}` : ''}`)
}

/** How many tasks match, without their rows (the page's meta.total). */
export async function countTasks(filters: TaskFilters): Promise<number> {
  return (await getTasks(filters)).meta.total
}

/** One task, or null when it doesn't exist or belongs to another organization (a 404 either way). */
export const getTask = cache(async (id: number): Promise<Task | null> => {
  try {
    return (await serverApi<{ data: Task }>(`/api/tasks/${id}`)).data
  } catch (error) {
    if (error instanceof ApiError && error.status === 404) {
      return null
    }
    throw error
  }
})

/** The organization's members: the assignee choices. */
export const getMembers = cache(async (): Promise<Member[]> => (await serverApi<{ data: Member[] }>('/api/members')).data)
