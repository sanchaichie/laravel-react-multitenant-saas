import { queryOptions, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ApiError, apiFetch } from '@/lib/api'
import { useCurrentOrganization } from '@/stores/auth'
import type { TaskSummary, TaskSummaryEdits, TaskSummaryResponse } from '@/types/api'

// Key ['org', id, 'tasks', 'detail', taskId, 'summary']: under the task's detail key,
// so whatever marks the task stale (deleting it) marks its summary stale too.

/** How often the card asks again while a summary is being generated. */
export const POLL_INTERVAL_MS = 3_000

export const taskSummaryQuery = (organizationId: number, taskId: number) =>
  queryOptions({
    queryKey: ['org', organizationId, 'tasks', 'detail', taskId, 'summary'],
    queryFn: ({ signal }) => apiFetch<TaskSummaryResponse>(`/api/tasks/${taskId}/summary`, { signal }),
  })

/**
 * A task's summary. While the latest one is pending it refetches every few seconds:
 * refetchInterval reads the cached answer each time, so polling stops by itself once
 * the summary is ready, and when the card unmounts. `gaveUpOn` (a pending summary's
 * id) stops it for that one, after the card has waited long enough.
 */
export function useTaskSummary(taskId: number, { gaveUpOn }: { gaveUpOn: number | null }) {
  const organizationId = useCurrentOrganization()?.id
  return useQuery({
    ...taskSummaryQuery(organizationId ?? 0, taskId),
    enabled: organizationId !== undefined,
    refetchInterval: (query) => {
      const latest = query.state.data?.data
      return latest?.status === 'pending' && latest.id !== gaveUpOn ? POLL_INTERVAL_MS : false
    },
  })
}

/** Ask for a new summary (or get the one already being generated). */
export function useRequestTaskSummary() {
  const queryClient = useQueryClient()
  const organizationId = useCurrentOrganization()?.id

  return useMutation({
    mutationFn: (taskId: number) => apiFetch<{ data: TaskSummary }>(`/api/tasks/${taskId}/summary`, { method: 'POST' }),
    onMutate: () => ({ organizationId }),
    onSuccess: ({ data }, taskId, context) => {
      const { queryKey } = taskSummaryQuery(context.organizationId ?? 0, taskId)
      // The answer is the new latest summary: show it at once (pending starts polling).
      // The approved one and `can` stay as they were.
      queryClient.setQueryData(queryKey, (old) => (old ? { ...old, data } : undefined))
      // Requesting also retired an unreviewed draft: refetch to be sure of the rest.
      return queryClient.invalidateQueries({ queryKey })
    },
  })
}

interface ReviewVariables {
  taskId: number
  summaryId: number
  approve: boolean
  edits?: TaskSummaryEdits
}

/** Approve (optionally with edits) or reject a draft. */
export function useReviewTaskSummary() {
  const queryClient = useQueryClient()
  const organizationId = useCurrentOrganization()?.id

  return useMutation({
    mutationFn: ({ taskId, summaryId, approve, edits }: ReviewVariables) =>
      apiFetch<{ data: TaskSummary }>(`/api/tasks/${taskId}/summaries/${summaryId}/${approve ? 'approve' : 'reject'}`, {
        method: 'POST',
        body: approve ? (edits ?? {}) : {},
      }),
    onMutate: () => ({ organizationId }),
    onSuccess: ({ data }, { taskId, approve }, context) => {
      // The reviewed draft is the latest summary; approved, it's also the task's summary.
      queryClient.setQueryData(taskSummaryQuery(context.organizationId ?? 0, taskId).queryKey, (old) =>
        old ? { ...old, data, approved: approve ? data : old.approved } : undefined,
      )
    },
    onError: (error, { taskId }, context) => {
      // Reviewed by someone else, or replaced meanwhile: show what it is now.
      if (error instanceof ApiError && (error.status === 409 || error.status === 404)) {
        void queryClient.invalidateQueries({ queryKey: taskSummaryQuery(context?.organizationId ?? 0, taskId).queryKey })
      }
    },
  })
}
