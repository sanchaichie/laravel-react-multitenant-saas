import { useState } from 'react'
import { SearchIcon, XIcon } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Checkbox } from '@/components/ui/checkbox'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { useMembers } from '@/features/members/api'
import { useDebouncedCallback } from '@/hooks/useDebouncedCallback'
import type { TaskFilters as Filters, TaskPriority, TaskSort, TaskStatus } from '@/types/api'
import { TASK_PRIORITIES, TASK_SORTS, TASK_STATUSES, hasActiveFilters, priorityLabel, sortLabel, statusLabel } from './filters'

// Radix Select items can't have an empty value: this one stands for "no filter".
const ANY = 'any'

interface TaskFiltersProps {
  filters: Filters
  onChange: (patch: Partial<Filters>) => void
  onClear: () => void
}

export function TaskFilters({ filters, onChange, onClear }: TaskFiltersProps) {
  const members = useMembers()
  const everyone = members.data?.data ?? []
  // An id from the URL that isn't in the list (members still loading, or someone who left).
  const unknownAssignee = filters.assignee && /^\d+$/.test(filters.assignee) && !everyone.some((m) => String(m.id) === filters.assignee)

  // What's typed, ahead of the URL by the debounce. When the URL's search changes
  // some other way (Back, Clear filters), the box follows it: state adjusted during
  // render, which React allows for "reset when a prop changes" instead of an effect.
  const urlSearch = filters.search ?? ''
  const [text, setText] = useState(urlSearch)
  const [seenSearch, setSeenSearch] = useState(urlSearch)
  if (urlSearch !== seenSearch) {
    setSeenSearch(urlSearch)
    if (urlSearch !== text.trim()) {
      setText(urlSearch)
    }
  }

  // `typedOn`: the URL's search when the keystroke happened. If the URL changed some
  // other way before the timer fires (Clear filters, Back, a switch), the search is
  // stale: drop it rather than write it back.
  const pushSearch = useDebouncedCallback((value: string, typedOn: string) => {
    if ((filters.search ?? '') === typedOn) {
      onChange({ search: value || undefined })
    }
  }, 300)

  function clear() {
    pushSearch.cancel()
    setText('')
    onClear()
  }

  return (
    <div className="flex flex-col gap-3">
      <div className="relative">
        <SearchIcon className="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground" aria-hidden />
        <Input
          type="search"
          aria-label="Search tasks"
          placeholder="Search title and description"
          className="pl-8"
          maxLength={100}
          value={text}
          onChange={(event) => {
            setText(event.target.value)
            pushSearch(event.target.value.trim(), urlSearch)
          }}
        />
      </div>

      <div className="grid grid-cols-2 gap-2 sm:flex sm:flex-wrap sm:items-center">
        <Select value={filters.status ?? ANY} onValueChange={(value) => onChange({ status: value === ANY ? undefined : (value as TaskStatus) })}>
          <SelectTrigger aria-label="Status" className="w-full sm:w-36">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value={ANY}>Any status</SelectItem>
            {TASK_STATUSES.map((status) => (
              <SelectItem key={status} value={status}>
                {statusLabel(status)}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>

        <Select
          value={filters.priority ?? ANY}
          onValueChange={(value) => onChange({ priority: value === ANY ? undefined : (value as TaskPriority) })}
        >
          <SelectTrigger aria-label="Priority" className="w-full sm:w-36">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value={ANY}>Any priority</SelectItem>
            {TASK_PRIORITIES.map((priority) => (
              <SelectItem key={priority} value={priority}>
                {priorityLabel(priority)}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>

        <Select value={filters.assignee ?? ANY} onValueChange={(value) => onChange({ assignee: value === ANY ? undefined : value })}>
          <SelectTrigger aria-label="Assignee" className="w-full sm:w-44">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value={ANY}>Anyone</SelectItem>
            <SelectItem value="me">Assigned to me</SelectItem>
            <SelectItem value="none">Unassigned</SelectItem>
            {unknownAssignee && <SelectItem value={filters.assignee ?? ''}>Someone else</SelectItem>}
            {everyone.map((member) => (
              <SelectItem key={member.id} value={String(member.id)}>
                {member.is_me ? `${member.name} (you)` : member.name}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>

        <Select value={filters.sort ?? 'newest'} onValueChange={(value) => onChange({ sort: value as TaskSort })}>
          <SelectTrigger aria-label="Sort by" className="w-full sm:w-36">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            {TASK_SORTS.map((sort) => (
              <SelectItem key={sort} value={sort}>
                {sortLabel(sort)}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>

        <div className="flex items-center gap-2 px-1 sm:px-2">
          <Checkbox
            id="filter-overdue"
            checked={filters.overdue ?? false}
            onCheckedChange={(checked) => onChange({ overdue: checked === true || undefined })}
          />
          <Label htmlFor="filter-overdue">Overdue</Label>
        </div>

        {hasActiveFilters(filters) && (
          <Button variant="ghost" size="sm" onClick={clear}>
            <XIcon aria-hidden />
            Clear filters
          </Button>
        )}
      </div>
    </div>
  )
}
