import { queryOptions, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { apiFetch } from '@/lib/api'
import { useCurrentOrganization } from '@/stores/auth'
import type { AiBudget, AiUsageReport } from '@/types/api'

// ['org', id, 'ai-usage', month]: one cached report per month ('' = this month).

export const aiUsageQuery = (organizationId: number, month: string) =>
  queryOptions({
    queryKey: ['org', organizationId, 'ai-usage', month],
    queryFn: ({ signal }) =>
      apiFetch<{ data: AiUsageReport }>(`/api/ai-usage${month ? `?month=${encodeURIComponent(month)}` : ''}`, { signal }),
  })

/** `enabled`: only with ai_usage.view (the API answers 403 otherwise). */
export function useAiUsage(month: string, enabled: boolean) {
  const organizationId = useCurrentOrganization()?.id
  return useQuery({
    ...aiUsageQuery(organizationId ?? 0, month),
    enabled: enabled && organizationId !== undefined,
    // Keep the month on screen while the next one loads, never across organizations.
    placeholderData: (previous, previousQuery) => (previousQuery?.queryKey[1] === organizationId ? previous : undefined),
  })
}

export function useUpdateAiBudget() {
  const queryClient = useQueryClient()
  const organizationId = useCurrentOrganization()?.id

  return useMutation({
    /** null goes back to the default budget; 0 turns AI off. */
    mutationFn: (monthlyBudgetUsd: number | null) =>
      apiFetch<{ data: AiBudget }>('/api/ai-budget', { method: 'PUT', body: { monthly_budget_usd: monthlyBudgetUsd } }),
    onMutate: () => ({ organizationId }),
    onSuccess: ({ data }, _amount, context) => {
      const prefix = ['org', context.organizationId, 'ai-usage']
      // Every cached month shows this month's budget: update them all at once
      // (setQueriesData, plural, matches by key prefix), then refetch in the background.
      queryClient.setQueriesData<{ data: AiUsageReport }>({ queryKey: prefix }, (old) =>
        old ? { data: { ...old.data, budget: data } } : old,
      )
      return queryClient.invalidateQueries({ queryKey: prefix })
    },
  })
}
