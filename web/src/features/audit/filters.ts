import type { AuditFilters } from '@/types/api'

const ACTION = /^[a-z_]+\.[a-z_]+$/
const ID = /^[1-9][0-9]{0,17}$/

/** A real calendar day as YYYY-MM-DD (rejects 2026-02-31). */
function day(value: string | null): string | undefined {
  if (!value || !/^\d{4}-\d{2}-\d{2}$/.test(value)) return undefined
  const date = new Date(`${value}T00:00:00Z`)
  return !Number.isNaN(date.getTime()) && date.toISOString().startsWith(value) ? value : undefined
}

/**
 * The filters in a URL's query string. Malformed values are dropped, and so is an
 * end date before the start date. An action the API doesn't know is dropped by the
 * page once it has the catalogue.
 */
export function parseAuditFilters(params: URLSearchParams): AuditFilters {
  const action = params.get('action')
  const actor = params.get('actor')
  const from = day(params.get('from'))
  let to = day(params.get('to'))
  if (from && to && to < from) to = undefined

  const filters: AuditFilters = {
    action: action && ACTION.test(action) ? action : undefined,
    actor: actor && ID.test(actor) ? actor : undefined,
    from,
    to,
  }

  // Without the undefined keys, so equal filters make equal query keys.
  return Object.fromEntries(Object.entries(filters).filter(([, value]) => value !== undefined)) as AuditFilters
}

/** The other way: for the URL, and for GET /api/audit-logs (same names). */
export function auditFiltersToSearchParams(filters: AuditFilters): URLSearchParams {
  const params = new URLSearchParams()
  if (filters.action) params.set('action', filters.action)
  if (filters.actor) params.set('actor', filters.actor)
  if (filters.from) params.set('from', filters.from)
  if (filters.to) params.set('to', filters.to)

  return params
}

export function hasAuditFilters(filters: AuditFilters): boolean {
  return Boolean(filters.action || filters.actor || filters.from || filters.to)
}
