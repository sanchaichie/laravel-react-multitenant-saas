import { formatUsd } from '@/lib/format'
import type { AuditLog } from '@/types/api'

// What AuditDetails shows, kept out of the component file (fast refresh wants
// component files to export only components).

/** A person or role, kept by name ({id, name}). */
export function isNamed(value: unknown): value is { name: string | null } {
  return typeof value === 'object' && value !== null && 'name' in value
}

/** The metadata worth showing, as [label, text] pairs. */
export function metadataFacts(metadata: AuditLog['metadata']): [string, string][] {
  const facts: [string, string][] = []
  if (typeof metadata.email === 'string') facts.push(['Email', metadata.email])
  if (isNamed(metadata.role)) facts.push(['Role', metadata.role.name ?? '—'])
  if (metadata.left === true) facts.push(['How', 'Left the organization'])
  if (typeof metadata.unassigned_tasks === 'number' && metadata.unassigned_tasks > 0) {
    facts.push(['Tasks unassigned', String(metadata.unassigned_tasks)])
  }
  if (metadata.edited === true) facts.push(['Edits', 'Edited before approving'])
  if (metadata.source === 'backfill') facts.push(['How', 'Requested by the ai:summarize-tasks command'])
  if (typeof metadata.pack === 'string') facts.push(['Credit pack', metadata.pack])
  if (typeof metadata.amount_usd === 'number') facts.push(['Amount', formatUsd(metadata.amount_usd)])
  if (metadata.source === 'stripe') facts.push(['How', 'Reported by Stripe'])
  return facts
}

/** Whether there's anything to show in the details. */
export function hasDetails(entry: AuditLog): boolean {
  return Object.keys(entry.changes).length > 0 || metadataFacts(entry.metadata).length > 0 || Boolean(entry.ip_address)
}
