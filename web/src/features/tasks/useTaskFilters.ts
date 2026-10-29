import { useCallback, useMemo } from 'react'
import { useSearchParams } from 'react-router'
import type { TaskFilters } from '@/types/api'
import { filtersToSearchParams, parseFilters } from './filters'

/**
 * The task list's filters, kept in the URL (useRoute().query + router.replace in
 * Nuxt). They're parsed from the URL on every render, never copied into state, so
 * reload, Back and shared links all just work. (Switching organization drops them:
 * it goes back to /tasks, see useEnterOrganization.)
 */
export function useTaskFilters() {
  const [params, setParams] = useSearchParams()
  const filters = useMemo(() => parseFilters(params), [params])

  /** Change some filters: back to page 1, replacing the history entry (Back skips each keystroke). */
  const setFilters = useCallback(
    (patch: Partial<TaskFilters>) =>
      setParams((current) => filtersToSearchParams({ ...parseFilters(current), page: undefined, ...patch }), { replace: true }),
    [setParams],
  )

  /** Remove the filters, keeping the sort. */
  const clearFilters = useCallback(
    () => setParams((current) => filtersToSearchParams({ sort: parseFilters(current).sort }), { replace: true }),
    [setParams],
  )

  return { filters, setFilters, clearFilters }
}
