import { queryOptions, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ApiError, apiFetch } from '@/lib/api'
import { useCurrentOrganization } from '@/stores/auth'
import type { Paginated, Task, TaskFilters, TaskPayload } from '@/types/api'
import { filtersToSearchParams } from './filters'

// Org-scoped keys: ['org', id, 'tasks', 'list', filters] and ['org', id, 'tasks', 'detail', taskId].
// A switch removes ['org'], and a mutation invalidates ['org', id, 'tasks', 'list'] (every filter).

export const tasksQuery = (organizationId: number, filters: TaskFilters) =>
  queryOptions({
    queryKey: ['org', organizationId, 'tasks', 'list', filters],
    queryFn: ({ signal }) => {
      const query = filtersToSearchParams(filters).toString()
      return apiFetch<Paginated<Task>>(`/api/tasks${query ? `?${query}` : ''}`, { signal })
    },
  })

export const taskQuery = (organizationId: number, taskId: number) =>
  queryOptions({
    queryKey: ['org', organizationId, 'tasks', 'detail', taskId],
    queryFn: ({ signal }) => apiFetch<{ data: Task }>(`/api/tasks/${taskId}`, { signal }),
  })

export function useTasks(filters: TaskFilters) {
  const organizationId = useCurrentOrganization()?.id
  return useQuery({
    ...tasksQuery(organizationId ?? 0, filters),
    enabled: organizationId !== undefined,
    // While the next page or filter loads, keep showing the rows we have (isPlaceholderData
    // says they're the old ones) instead of falling back to a loading state. Like
    // keepPreviousData, but never across organizations: after a switch the old rows
    // aren't this organization's, so it shows the loading state instead.
    placeholderData: (previous, previousQuery) => (previousQuery?.queryKey[1] === organizationId ? previous : undefined),
  })
}

/** One task; `taskId` null (a malformed id in the URL) makes no request. */
export function useTask(taskId: number | null) {
  const organizationId = useCurrentOrganization()?.id
  return useQuery({
    ...taskQuery(organizationId ?? 0, taskId ?? 0),
    enabled: organizationId !== undefined && taskId !== null,
    // A 404 is an answer (not found, or another organization's): don't retry it.
    retry: (failureCount, error) => !(error instanceof ApiError && error.status === 404) && failureCount < 1,
  })
}

export function useCreateTask() {
  const queryClient = useQueryClient()
  const organizationId = useCurrentOrganization()?.id

  return useMutation({
    mutationFn: (body: TaskPayload) => apiFetch<{ data: Task }>('/api/tasks', { method: 'POST', body }),
    // The organization the request was made in, read before it's sent: after a switch
    // mid-request, the answer belongs to that one's cache, not the new one's.
    onMutate: () => ({ organizationId }),
    onSuccess: ({ data }, _body, context) => {
      queryClient.setQueryData(taskQuery(context.organizationId ?? 0, data.id).queryKey, { data })
      return queryClient.invalidateQueries({ queryKey: ['org', context.organizationId, 'tasks', 'list'] })
    },
  })
}

export function useUpdateTask() {
  const queryClient = useQueryClient()
  const organizationId = useCurrentOrganization()?.id

  return useMutation({
    mutationFn: ({ id, ...body }: Partial<TaskPayload> & { id: number }) =>
      apiFetch<{ data: Task }>(`/api/tasks/${id}`, { method: 'PATCH', body }),
    onMutate: () => ({ organizationId }),
    onSuccess: ({ data }, _body, context) => {
      // The answer is the saved task: put it straight into the detail query (no refetch),
      // and let the lists refetch, since it may now sort or filter differently.
      queryClient.setQueryData(taskQuery(context.organizationId ?? 0, data.id).queryKey, { data })
      return queryClient.invalidateQueries({ queryKey: ['org', context.organizationId, 'tasks', 'list'] })
    },
  })
}

export function useDeleteTask() {
  const queryClient = useQueryClient()
  const organizationId = useCurrentOrganization()?.id

  return useMutation({
    mutationFn: (task: Task) => apiFetch<void>(`/api/tasks/${task.id}`, { method: 'DELETE' }),
    onMutate: () => ({ organizationId }),
    onSuccess: (_data, task, context) => {
      // Marked stale, not removed: the detail page is still mounted until it navigates
      // away, and removing its query would refetch it (a 404 flash). Coming Back to it
      // later refetches and shows "not found".
      void queryClient.invalidateQueries({ queryKey: taskQuery(context.organizationId ?? 0, task.id).queryKey, refetchType: 'none' })
      return queryClient.invalidateQueries({ queryKey: ['org', context.organizationId, 'tasks', 'list'] })
    },
  })
}
