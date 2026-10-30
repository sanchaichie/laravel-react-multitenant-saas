import { queryOptions, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { apiFetch } from '@/lib/api'
import { useCurrentOrganization } from '@/stores/auth'
import type { TaskChatMessage } from '@/types/api'

// Under the task's detail key: switching organization drops it with the task.
export const taskChatQuery = (organizationId: number, taskId: number) =>
  queryOptions({
    queryKey: ['org', organizationId, 'tasks', 'detail', taskId, 'chat'],
    queryFn: ({ signal }) => apiFetch<{ data: TaskChatMessage[] }>(`/api/tasks/${taskId}/chat`, { signal }),
  })

/** Your last 50 messages about the task, oldest first. Fresh each time the chat opens. */
export function useTaskChat(taskId: number) {
  const organizationId = useCurrentOrganization()?.id
  return useQuery({
    ...taskChatQuery(organizationId ?? 0, taskId),
    enabled: organizationId !== undefined,
    refetchOnMount: 'always',
  })
}

export function useClearTaskChat(taskId: number) {
  const queryClient = useQueryClient()
  const organizationId = useCurrentOrganization()?.id

  return useMutation({
    mutationFn: () => apiFetch<void>(`/api/tasks/${taskId}/chat`, { method: 'DELETE' }),
    onMutate: async () => {
      // A history refetch still in flight would bring the cleared messages back.
      await queryClient.cancelQueries({ queryKey: taskChatQuery(organizationId ?? 0, taskId).queryKey })
      return { organizationId }
    },
    // In useMutation, not mutate(): it must happen even if the chat was closed meanwhile.
    onSuccess: (_data, _vars, context) => {
      queryClient.setQueryData(taskChatQuery(context.organizationId ?? 0, taskId).queryKey, { data: [] })
    },
  })
}
