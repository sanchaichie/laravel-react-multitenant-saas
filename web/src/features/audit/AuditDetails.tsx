import { priorityLabel, statusLabel } from '@/features/tasks/filters'
import { formatUsd } from '@/lib/format'
import type { AuditLog, PermissionInfo, TaskPriority, TaskStatus } from '@/types/api'
import { isNamed, metadataFacts } from './details'

const fieldLabels: Record<string, string> = {
  title: 'Title',
  description: 'Description',
  status: 'Status',
  priority: 'Priority',
  due_date: 'Due date',
  assignee: 'Assignee',
  name: 'Name',
  permissions: 'Permissions',
  role: 'Role',
  ai_monthly_budget_usd: 'Monthly AI budget',
}

const fieldLabel = (key: string) => fieldLabels[key] ?? key

/**
 * What an entry recorded: each changed field as old → new, then the extra facts
 * (email, role, ...). Everything is rendered as text: JSX escapes it, so a task
 * titled "<b>hi</b>" shows exactly that.
 */
export function AuditDetails({ entry, permissions }: { entry: AuditLog; permissions: PermissionInfo[] }) {
  const changes = Object.entries(entry.changes)
  const facts = metadataFacts(entry.metadata)

  return (
    <dl className="grid gap-2 text-sm sm:grid-cols-[10rem_1fr]">
      {changes.map(([key, { old, new: value }]) => (
        <div key={key} className="contents">
          <dt className="font-medium">{fieldLabel(key)}</dt>
          <dd className="min-w-0 break-words">
            {key === 'permissions' ? (
              <PermissionChange old={old} value={value} permissions={permissions} />
            ) : (
              <>
                <Value field={key} value={old} />
                <span className="px-1.5 text-muted-foreground" aria-label="changed to">
                  →
                </span>
                <Value field={key} value={value} />
              </>
            )}
          </dd>
        </div>
      ))}
      {facts.map(([label, value]) => (
        <div key={label} className="contents">
          <dt className="font-medium">{label}</dt>
          <dd className="min-w-0 break-words">{value}</dd>
        </div>
      ))}
      {entry.ip_address && (
        <>
          <dt className="font-medium">IP address</dt>
          <dd>{entry.ip_address}</dd>
        </>
      )}
    </dl>
  )
}

function Value({ field, value }: { field: string; value: unknown }) {
  // null is the default budget, not "nothing".
  if (field === 'ai_monthly_budget_usd') {
    return <span>{value === null ? 'Default' : formatUsd(Number(value))}</span>
  }
  if (value === null || value === undefined || value === '') {
    return <span className="text-muted-foreground">—</span>
  }
  return <span>{display(field, value)}</span>
}

function display(field: string, value: unknown): string {
  if (field === 'status' && typeof value === 'string') return statusLabel(value as TaskStatus) ?? value
  if (field === 'priority' && typeof value === 'string') return priorityLabel(value as TaskPriority) ?? value
  // A person or role, kept by name ({id, name}).
  if (isNamed(value)) return value.name ?? 'Someone who has left'
  if (Array.isArray(value)) return value.join(', ')
  return String(value)
}

/** A permission list as what was added and removed, by label. */
function PermissionChange({ old, value, permissions }: { old: unknown; value: unknown; permissions: PermissionInfo[] }) {
  const before = Array.isArray(old) ? (old as string[]) : []
  const after = Array.isArray(value) ? (value as string[]) : []
  const label = (permission: string) => permissions.find((p) => p.value === permission)?.label ?? permission
  const added = after.filter((p) => !before.includes(p))
  const removed = before.filter((p) => !after.includes(p))

  if (added.length === 0 && removed.length === 0) {
    return <span className="text-muted-foreground">None</span>
  }

  return (
    <ul className="grid gap-0.5">
      {added.map((p) => (
        <li key={`+${p}`}>
          <span className="font-medium text-emerald-700 dark:text-emerald-400">Added:</span> {label(p)}
        </li>
      ))}
      {removed.map((p) => (
        <li key={`-${p}`}>
          <span className="font-medium text-destructive">Removed:</span> {label(p)}
        </li>
      ))}
    </ul>
  )
}
