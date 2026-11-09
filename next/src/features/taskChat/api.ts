'use client'

import { queryOptions, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import type { TaskChatMessage } from '@shared/types/api'
import { clientFetch } from '@/lib/clientApi'

// Client-side data (TanStack Query) through the /api proxy. Keys as in web/:
// org-scoped and under the task's detail key.
export const taskChatQuery = (organizationId: number, taskId: number) =>
  queryOptions({
    queryKey: ['org', organizationId, 'tasks', 'detail', taskId, 'chat'],
    queryFn: ({ signal }) => clientFetch<{ data: TaskChatMessage[] }>(`/api/tasks/${taskId}/chat`, { organizationId, signal }),
  })

/**
 * Your last 50 messages about the task, oldest first. Fresh each time the chat
 * opens, and dropped from the cache once it closes (gcTime 0): history is private
 * to one user, and the cookie can change to another user in another tab.
 */
export function useTaskChat(organizationId: number, taskId: number) {
  return useQuery({ ...taskChatQuery(organizationId, taskId), refetchOnMount: 'always', gcTime: 0 })
}

export function useClearTaskChat(organizationId: number, taskId: number) {
  const queryClient = useQueryClient()
  const { queryKey } = taskChatQuery(organizationId, taskId)

  return useMutation({
    mutationFn: () => clientFetch<void>(`/api/tasks/${taskId}/chat`, { method: 'DELETE', organizationId }),
    // A history refetch still in flight would bring the cleared messages back.
    onMutate: () => queryClient.cancelQueries({ queryKey }),
    // In useMutation, not mutate(): it must happen even if the sheet was closed meanwhile.
    onSuccess: () => queryClient.setQueryData(queryKey, { data: [] }),
  })
}
