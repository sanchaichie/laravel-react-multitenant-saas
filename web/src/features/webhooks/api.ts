import { infiniteQueryOptions, queryOptions, useInfiniteQuery, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { apiFetch } from '@/lib/api'
import { useCurrentOrganization } from '@/stores/auth'
import type { CursorPage, WebhookDelivery, WebhookEndpoint, WebhookPayload, WebhookWithSecret } from '@/types/api'

// ['org', id, 'webhooks'] the list, ['org', id, 'webhooks', webhookId] one webhook,
// and its deliveries under that: invalidating a webhook refreshes its deliveries too.

export const webhooksQuery = (organizationId: number) =>
  queryOptions({
    queryKey: ['org', organizationId, 'webhooks'],
    queryFn: ({ signal }) => apiFetch<{ data: WebhookEndpoint[]; meta: { max: number } }>('/api/webhooks', { signal }),
  })

export const webhookQuery = (organizationId: number, webhookId: number) =>
  queryOptions({
    queryKey: ['org', organizationId, 'webhooks', webhookId],
    queryFn: ({ signal }) => apiFetch<{ data: WebhookEndpoint }>(`/api/webhooks/${webhookId}`, { signal }),
  })

export const deliveriesQuery = (organizationId: number, webhookId: number) =>
  infiniteQueryOptions({
    queryKey: ['org', organizationId, 'webhooks', webhookId, 'deliveries'],
    queryFn: ({ pageParam, signal }) =>
      apiFetch<CursorPage<WebhookDelivery>>(
        `/api/webhooks/${webhookId}/deliveries${pageParam ? `?cursor=${encodeURIComponent(pageParam)}` : ''}`,
        { signal },
      ),
    initialPageParam: null as string | null,
    getNextPageParam: (lastPage) => lastPage.meta.next_cursor,
  })

export function useWebhooks(enabled: boolean) {
  const organizationId = useCurrentOrganization()?.id
  return useQuery({ ...webhooksQuery(organizationId ?? 0), enabled: enabled && organizationId !== undefined })
}

export function useWebhook(webhookId: number | null) {
  const organizationId = useCurrentOrganization()?.id
  return useQuery({
    ...webhookQuery(organizationId ?? 0, webhookId ?? 0),
    enabled: organizationId !== undefined && webhookId !== null,
    retry: false,
  })
}

const POLL_INTERVAL_MS = 3000

/**
 * A webhook's deliveries, 20 at a time. While a delivery's first attempt is still
 * on its way, it refetches every few seconds: refetchInterval is asked again after
 * each fetch, with every page loaded, so polling stops by itself once they've all
 * been tried. Retries hours away don't count. `pollUntil` (a time on this browser's
 * clock) stops it anyway, for a queue that isn't running.
 */
export function useWebhookDeliveries(webhookId: number, { pollUntil }: { pollUntil: number }) {
  const organizationId = useCurrentOrganization()?.id
  return useInfiniteQuery({
    ...deliveriesQuery(organizationId ?? 0, webhookId),
    enabled: organizationId !== undefined,
    refetchInterval: (query) => {
      const waiting = query.state.data?.pages.some((page) =>
        page.data.some((delivery) => delivery.status === 'pending' && delivery.attempts === 0),
      )
      return waiting && Date.now() < pollUntil ? POLL_INTERVAL_MS : false
    },
  })
}

/**
 * Refresh the list, and the one webhook (with its deliveries) if given. Mutations
 * read the organization in onMutate, so a switch meanwhile refreshes the right one.
 */
function useInvalidateWebhooks() {
  const queryClient = useQueryClient()

  return (organizationId: number | undefined, webhookId?: number) =>
    Promise.all([
      queryClient.invalidateQueries({ queryKey: ['org', organizationId, 'webhooks'], exact: true }),
      webhookId !== undefined && queryClient.invalidateQueries({ queryKey: ['org', organizationId, 'webhooks', webhookId] }),
    ])
}

export const CREATE_WEBHOOK_KEY = ['create-webhook']

export function useCreateWebhook() {
  const organizationId = useCurrentOrganization()?.id
  const invalidate = useInvalidateWebhooks()

  return useMutation({
    // The Add dialog reads this (useIsMutating) to stay open while the secret is on its way.
    mutationKey: CREATE_WEBHOOK_KEY,
    mutationFn: (body: WebhookPayload) => apiFetch<WebhookWithSecret>('/api/webhooks', { method: 'POST', body }),
    onMutate: () => ({ organizationId }),
    onSuccess: (_data, _body, context) => invalidate(context.organizationId),
  })
}

export function useUpdateWebhook(webhookId: number) {
  const organizationId = useCurrentOrganization()?.id
  const invalidate = useInvalidateWebhooks()

  return useMutation({
    mutationFn: (body: WebhookPayload) => apiFetch<{ data: WebhookEndpoint }>(`/api/webhooks/${webhookId}`, { method: 'PATCH', body }),
    onMutate: () => ({ organizationId }),
    onSuccess: (_data, _body, context) => invalidate(context.organizationId, webhookId),
  })
}

export function useDeleteWebhook() {
  const queryClient = useQueryClient()
  const organizationId = useCurrentOrganization()?.id

  return useMutation({
    mutationFn: (webhook: WebhookEndpoint) => apiFetch<void>(`/api/webhooks/${webhook.id}`, { method: 'DELETE' }),
    onMutate: () => ({ organizationId }),
    onSuccess: (_data, webhook, context) =>
      Promise.all([
        // The detail page is still mounted until it navigates away: mark it stale without
        // refetching (a 404), rather than removing it (its observers would refetch it).
        queryClient.invalidateQueries({ queryKey: ['org', context.organizationId, 'webhooks', webhook.id], refetchType: 'none' }),
        queryClient.invalidateQueries({ queryKey: ['org', context.organizationId, 'webhooks'], exact: true }),
      ]),
  })
}

/** The new secret is in the answer only: the caller shows it once, it never goes in the cache. */
export function useRollWebhookSecret(webhookId: number) {
  const organizationId = useCurrentOrganization()?.id
  const invalidate = useInvalidateWebhooks()

  return useMutation({
    mutationFn: () => apiFetch<WebhookWithSecret>(`/api/webhooks/${webhookId}/secret`, { method: 'POST' }),
    onMutate: () => ({ organizationId }),
    onSuccess: (_data, _vars, context) => invalidate(context.organizationId, webhookId),
  })
}

export function useSendTestWebhook(webhookId: number) {
  const organizationId = useCurrentOrganization()?.id
  const invalidate = useInvalidateWebhooks()

  return useMutation({
    mutationFn: () => apiFetch<{ data: WebhookDelivery }>(`/api/webhooks/${webhookId}/test`, { method: 'POST' }),
    onMutate: () => ({ organizationId }),
    onSuccess: (_data, _vars, context) => invalidate(context.organizationId, webhookId),
  })
}

export function useResendDelivery(webhookId: number) {
  const organizationId = useCurrentOrganization()?.id
  const invalidate = useInvalidateWebhooks()

  return useMutation({
    mutationFn: (delivery: WebhookDelivery) =>
      apiFetch<{ data: WebhookDelivery }>(`/api/webhooks/${webhookId}/deliveries/${delivery.id}/resend`, { method: 'POST' }),
    onMutate: () => ({ organizationId }),
    onSuccess: (_data, _delivery, context) => invalidate(context.organizationId, webhookId),
  })
}
