import { useCallback, useMemo } from 'react'
import { useSearchParams } from 'react-router'
import type { AuditFilters } from '@/types/api'
import { auditFiltersToSearchParams, parseAuditFilters } from './filters'

/**
 * The audit log's filters, kept in the URL like the task list's (see
 * useTaskFilters): parsed on every render, changed by replacing the history entry.
 */
export function useAuditFilters() {
  const [params, setParams] = useSearchParams()
  const filters = useMemo(() => parseAuditFilters(params), [params])

  const setFilters = useCallback(
    (patch: Partial<AuditFilters>) =>
      setParams((current) => auditFiltersToSearchParams({ ...parseAuditFilters(current), ...patch }), { replace: true }),
    [setParams],
  )

  const clearFilters = useCallback(() => setParams(new URLSearchParams(), { replace: true }), [setParams])

  return { filters, setFilters, clearFilters }
}
