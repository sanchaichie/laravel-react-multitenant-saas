import { infiniteQueryOptions, queryOptions, useInfiniteQuery, useQuery } from '@tanstack/react-query'
import { apiFetch } from '@/lib/api'
import { useCurrentOrganization } from '@/stores/auth'
import type { AuditActionInfo, AuditFilters, AuditLog, CursorPage } from '@/types/api'
import { auditFiltersToSearchParams } from './filters'

// ['org', id, 'audit-log', filters]: one cached list of pages per set of filters.
// Nothing invalidates it: the page refetches when it mounts, which is enough for a log.

export const auditLogQuery = (organizationId: number, filters: AuditFilters) =>
  infiniteQueryOptions({
    queryKey: ['org', organizationId, 'audit-log', filters],
    queryFn: ({ pageParam, signal }) => {
      const params = auditFiltersToSearchParams(filters)
      if (pageParam) params.set('cursor', pageParam)
      const query = params.toString()
      return apiFetch<CursorPage<AuditLog>>(`/api/audit-logs${query ? `?${query}` : ''}`, { signal })
    },
    initialPageParam: null as string | null,
    // null (the last page) tells TanStack there's no next one: hasNextPage turns false.
    getNextPageParam: (lastPage) => lastPage.meta.next_cursor,
  })

/**
 * The action catalogue. The same everywhere, but under ['org'] anyway: the endpoint
 * answers 403 without audit_log.view, and that answer belongs to one organization
 * (a switch drops it with the rest).
 */
export const auditActionsQuery = (organizationId: number) =>
  queryOptions({
    queryKey: ['org', organizationId, 'audit-actions'],
    queryFn: ({ signal }) => apiFetch<{ data: AuditActionInfo[] }>('/api/audit-log/actions', { signal }),
    staleTime: Infinity,
  })

/** `enabled`: the page waits for the catalogue (to drop an unknown action) and the permission. */
export function useAuditLog(filters: AuditFilters, enabled: boolean) {
  const organizationId = useCurrentOrganization()?.id
  return useInfiniteQuery({ ...auditLogQuery(organizationId ?? 0, filters), enabled: enabled && organizationId !== undefined })
}

export function useAuditActions(enabled: boolean) {
  const organizationId = useCurrentOrganization()?.id
  return useQuery({ ...auditActionsQuery(organizationId ?? 0), enabled: enabled && organizationId !== undefined })
}
