import { useMemo } from 'react'
import { XIcon } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Select, SelectContent, SelectGroup, SelectItem, SelectLabel, SelectTrigger, SelectValue } from '@/components/ui/select'
import { useMembers } from '@/features/members/api'
import type { AuditActionInfo, AuditFilters as Filters } from '@/types/api'
import { hasAuditFilters } from './filters'

// Radix Select items can't have an empty value: this one stands for "no filter".
const ANY = 'any'

interface AuditFiltersProps {
  filters: Filters
  actions: AuditActionInfo[]
  onChange: (patch: Partial<Filters>) => void
  onClear: () => void
}

export function AuditFilters({ filters, actions, onChange, onClear }: AuditFiltersProps) {
  const members = useMembers()
  const everyone = members.data?.data ?? []
  // Someone in the URL who isn't a member now (left, or still loading): keep them selectable.
  const unknownActor = filters.actor && !everyone.some((m) => String(m.id) === filters.actor) ? filters.actor : undefined

  // The catalogue grouped by area, computed only when it changes (a computed in Vue).
  const groups = useMemo(() => {
    const byGroup = new Map<string, AuditActionInfo[]>()
    for (const action of actions) {
      byGroup.set(action.group, [...(byGroup.get(action.group) ?? []), action])
    }
    return [...byGroup]
  }, [actions])

  return (
    <div className="grid grid-cols-2 gap-2 sm:flex sm:flex-wrap sm:items-end">
      <div className="col-span-2 grid gap-1.5 sm:w-56">
        <Label htmlFor="audit-action">Action</Label>
        <Select value={filters.action ?? ANY} onValueChange={(value) => onChange({ action: value === ANY ? undefined : value })}>
          <SelectTrigger id="audit-action" className="w-full">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value={ANY}>Any action</SelectItem>
            {groups.map(([group, items]) => (
              <SelectGroup key={group}>
                <SelectLabel>{group}</SelectLabel>
                {items.map((action) => (
                  <SelectItem key={action.value} value={action.value}>
                    {action.label}
                  </SelectItem>
                ))}
              </SelectGroup>
            ))}
          </SelectContent>
        </Select>
      </div>

      <div className="col-span-2 grid gap-1.5 sm:w-48">
        <Label htmlFor="audit-actor">Who</Label>
        <Select value={filters.actor ?? ANY} onValueChange={(value) => onChange({ actor: value === ANY ? undefined : value })}>
          <SelectTrigger id="audit-actor" className="w-full">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value={ANY}>Anyone</SelectItem>
            {everyone.map((member) => (
              <SelectItem key={member.id} value={String(member.id)}>
                {member.name}
              </SelectItem>
            ))}
            {unknownActor && <SelectItem value={unknownActor}>A former member</SelectItem>}
          </SelectContent>
        </Select>
      </div>

      <div className="grid gap-1.5">
        <Label htmlFor="audit-from">From</Label>
        <Input
          id="audit-from"
          type="date"
          value={filters.from ?? ''}
          max={filters.to}
          onChange={(event) => onChange({ from: event.target.value || undefined })}
        />
      </div>
      <div className="grid gap-1.5">
        <Label htmlFor="audit-to">To</Label>
        <Input
          id="audit-to"
          type="date"
          value={filters.to ?? ''}
          min={filters.from}
          onChange={(event) => onChange({ to: event.target.value || undefined })}
        />
      </div>

      {hasAuditFilters(filters) && (
        <Button variant="ghost" className="col-span-2 sm:col-span-1" onClick={onClear}>
          <XIcon aria-hidden />
          Clear filters
        </Button>
      )}
    </div>
  )
}
