import { useState } from 'react'
import { ChevronDownIcon } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible'
import { formatDateTime } from '@/lib/format'
import { cn } from '@/lib/utils'
import type { AuditLog, PermissionInfo } from '@/types/api'
import { AuditDetails } from './AuditDetails'
import { hasDetails } from './details'

export function AuditLogList({ entries, permissions }: { entries: AuditLog[]; permissions: PermissionInfo[] }) {
  return (
    <ul className="divide-y" aria-label="Audit log entries">
      {entries.map((entry) => (
        // `key` is what ties each row's open/closed state to its entry, so loading
        // more rows (or new ones arriving on top) never opens the wrong one.
        <AuditLogRow key={entry.id} entry={entry} permissions={permissions} />
      ))}
    </ul>
  )
}

function AuditLogRow({ entry, permissions }: { entry: AuditLog; permissions: PermissionInfo[] }) {
  // Each row owns its state (a ref inside a v-for child component, in Vue).
  const [open, setOpen] = useState(false)
  const details = hasDetails(entry)
  // "Ana joined", not "Ana joined: Ana" (the same for someone leaving).
  const aboutThemselves = entry.subject?.type === 'member' && entry.actor !== null && entry.subject.id === entry.actor.id
  const subjectLabel = aboutThemselves ? null : entry.subject?.label

  return (
    <li>
      <Collapsible open={open} onOpenChange={setOpen}>
        <div className="flex flex-col gap-1 py-3 sm:flex-row sm:items-center sm:gap-4">
          <time dateTime={entry.created_at} className="shrink-0 text-xs text-muted-foreground sm:w-44">
            {formatDateTime(entry.created_at)}
          </time>
          <p className="min-w-0 flex-1 text-sm break-words">
            <span className="font-medium">{entry.actor?.name ?? 'Someone'}</span>{' '}
            <span className="text-muted-foreground">{entry.action_label.toLowerCase()}</span>
            {subjectLabel && (
              <>
                {': '}
                <span className="font-medium">{subjectLabel}</span>
              </>
            )}
          </p>
          {details && (
            <CollapsibleTrigger asChild>
              <Button variant="ghost" size="sm" className="self-start sm:self-auto">
                Details
                <ChevronDownIcon aria-hidden className={cn('transition-transform', open && 'rotate-180')} />
              </Button>
            </CollapsibleTrigger>
          )}
        </div>
        {details && (
          <CollapsibleContent className="pb-3 sm:pl-48">
            <AuditDetails entry={entry} permissions={permissions} />
          </CollapsibleContent>
        )}
      </Collapsible>
    </li>
  )
}
