import { useCallback, useMemo } from 'react'
import { useSearchParams } from 'react-router'
import { type AdminSearch, adminSearchParams } from './api'

/** ?q=&page= as the admin lists read them; anything else is dropped. */
export function parseAdminSearch(params: URLSearchParams): AdminSearch {
  const q = params.get('q')?.trim().slice(0, 100) || undefined
  const page = Number(params.get('page'))
  return { q, page: Number.isInteger(page) && page > 1 ? page : undefined }
}

/**
 * The search box and page of an admin list, kept in the URL (parsed every render,
 * never copied into state). A new search replaces the history entry and goes back
 * to page 1; page links push one (pageLink).
 */
export function useAdminSearch(path: string) {
  const [params, setParams] = useSearchParams()
  const search = useMemo(() => parseAdminSearch(params), [params])

  const setQuery = useCallback(
    (q: string) => setParams(adminSearchParams({ q: q.trim() || undefined }), { replace: true }),
    [setParams],
  )

  const pageLink = useCallback(
    (page: number) => {
      const query = adminSearchParams({ ...search, page }).toString()
      return query ? `${path}?${query}` : path
    },
    [path, search],
  )

  return { search, setQuery, pageLink }
}
