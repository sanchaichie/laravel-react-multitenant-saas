import { infiniteQueryOptions, queryOptions, useInfiniteQuery, useMutation, useQuery } from '@tanstack/react-query'
import { apiFetch } from '@/lib/api'
import { leaveApp } from '@/lib/leaveApp'
import { useCurrentOrganization } from '@/stores/auth'
import type { Billing, InvoicePage } from '@/types/api'

// ['org', id, 'billing'] and ['org', id, 'billing', 'invoices']. Nothing in the app
// changes billing (only Stripe's webhook does), so no mutation invalidates them: the
// billing page polls after coming back from Checkout instead.

export const billingQuery = (organizationId: number) =>
  queryOptions({
    queryKey: ['org', organizationId, 'billing'],
    queryFn: ({ signal }) => apiFetch<{ data: Billing }>('/api/billing', { signal }),
  })

export const invoicesQuery = (organizationId: number) =>
  infiniteQueryOptions({
    queryKey: ['org', organizationId, 'billing', 'invoices'],
    queryFn: ({ pageParam, signal }) =>
      apiFetch<InvoicePage>(`/api/billing/invoices${pageParam ? `?starting_after=${encodeURIComponent(pageParam)}` : ''}`, { signal }),
    initialPageParam: null as string | null,
    getNextPageParam: (lastPage) => lastPage.meta.next_cursor,
  })

interface BillingOptions {
  /** Only with billing.manage (the API answers 403 otherwise). */
  enabled: boolean
  /**
   * Milliseconds until the next refetch, or false, given the latest answer (while
   * waiting for Stripe's webhook). Asked again after every fetch.
   */
  pollEvery?: (billing: Billing | undefined) => number | false
}

export function useBilling({ enabled, pollEvery }: BillingOptions) {
  const organizationId = useCurrentOrganization()?.id
  return useQuery({
    ...billingQuery(organizationId ?? 0),
    enabled: enabled && organizationId !== undefined,
    // A function of the cached answer: polling stops by itself once it returns false,
    // and when the page unmounts or the organization (so the key) changes.
    refetchInterval: (query) => pollEvery?.(query.state.data?.data) ?? false,
  })
}

export function useInvoices(enabled: boolean) {
  const organizationId = useCurrentOrganization()?.id
  return useInfiniteQuery({ ...invoicesQuery(organizationId ?? 0), enabled: enabled && organizationId !== undefined })
}

/** Leave the app for the Stripe page the API made. */
function goTo({ data }: { data: { url: string } }) {
  leaveApp(data.url)
}

/** Stripe Checkout for a plan (by key). Nothing changes until Stripe's webhook says it's paid. */
export function useStartCheckout() {
  return useMutation({
    mutationFn: (plan: string) => apiFetch<{ data: { url: string } }>('/api/billing/checkout', { method: 'POST', body: { plan } }),
    onSuccess: goTo,
  })
}

/** Stripe Checkout for an AI credit pack (by key). */
export function useBuyCredits() {
  return useMutation({
    mutationFn: (pack: string) => apiFetch<{ data: { url: string } }>('/api/billing/credits/checkout', { method: 'POST', body: { pack } }),
    onSuccess: goTo,
  })
}

/** Stripe's Customer Portal: the card, cancelling, Stripe's own invoice list. */
export function useOpenPortal() {
  return useMutation({
    mutationFn: () => apiFetch<{ data: { url: string } }>('/api/billing/portal', { method: 'POST' }),
    onSuccess: goTo,
  })
}
