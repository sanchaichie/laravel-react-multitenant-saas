'use client'

import { useEffect, useRef, useState, useTransition } from 'react'
import { usePathname, useRouter } from 'next/navigation'
import type { Member, TaskFilters as Filters } from '@shared/types/api'
import { Button } from '@/components/ui/button'
import { Checkbox } from '@/components/ui/checkbox'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Spinner } from '@/components/ui/spinner'
import { useDebouncedCallback } from '@/hooks/use-debounced-callback'
import { SearchIcon, XIcon } from '@/icons'
import {
  TASK_PRIORITIES,
  TASK_SORTS,
  TASK_STATUSES,
  filtersToSearchParams,
  hasActiveFilters,
  priorityLabel,
  sortLabel,
  statusLabel,
} from './filters'

// A Select item can't have an empty value: this one stands for "no filter".
const ANY = 'any'

interface TaskFiltersProps {
  /** Parsed by the page from its searchParams: the URL is the only copy. */
  filters: Filters
  members: Member[]
}

/**
 * The filter bar. It only changes the URL (router.replace, no new history entry);
 * Next.js then re-renders the Server Component page with the new searchParams,
 * which fetches the list. useTransition keeps the old rows on screen meanwhile and
 * says it's pending (web/ gets the same from TanStack Query's placeholderData).
 */
export function TaskFilters({ filters, members }: TaskFiltersProps) {
  const router = useRouter()
  const pathname = usePathname()
  const [isPending, startTransition] = useTransition()

  // The filters as last asked for. The `filters` prop only catches up once the new
  // page has rendered, so two quick changes (a search, then a status) must build on
  // this, or the second would undo the first.
  const latest = useRef(filters)
  useEffect(() => {
    latest.current = filters
  }, [filters])

  function navigate(next: Filters) {
    latest.current = next
    const query = filtersToSearchParams(next).toString()
    startTransition(() => router.replace(query ? `${pathname}?${query}` : pathname, { scroll: false }))
  }

  /** Change some filters: back to page 1. */
  function change(patch: Partial<Filters>) {
    navigate({ ...latest.current, page: undefined, ...patch })
  }

  // What's typed, ahead of the URL by the debounce. When the URL's search changes
  // another way (Back, Clear filters), the box follows it: state adjusted during
  // render, React's "reset when a prop changes" pattern, instead of an effect.
  const urlSearch = filters.search ?? ''
  const [text, setText] = useState(urlSearch)
  const [seenSearch, setSeenSearch] = useState(urlSearch)
  if (urlSearch !== seenSearch) {
    setSeenSearch(urlSearch)
    if (urlSearch !== text.trim()) {
      setText(urlSearch)
    }
  }

  const pushSearch = useDebouncedCallback((value: string) => change({ search: value || undefined }), 300)

  function clear() {
    pushSearch.cancel()
    setText('')
    navigate({ sort: latest.current.sort })
  }

  const unknownAssignee =
    filters.assignee && /^\d+$/.test(filters.assignee) && !members.some((member) => String(member.id) === filters.assignee)
  const assigneeItems = [
    { value: ANY, label: 'Anyone' },
    { value: 'me', label: 'Assigned to me' },
    { value: 'none', label: 'Unassigned' },
    ...(unknownAssignee ? [{ value: filters.assignee ?? '', label: 'Someone else' }] : []),
    ...members.map((member) => ({ value: String(member.id), label: member.is_me ? `${member.name} (you)` : member.name })),
  ]
  const statusItems = [{ value: ANY, label: 'Any status' }, ...TASK_STATUSES.map((value) => ({ value, label: statusLabel(value) }))]
  const priorityItems = [
    { value: ANY, label: 'Any priority' },
    ...TASK_PRIORITIES.map((value) => ({ value, label: priorityLabel(value) })),
  ]
  const sortItems = TASK_SORTS.map((value) => ({ value, label: sortLabel(value) }))

  return (
    <div className="flex flex-col gap-3" aria-busy={isPending}>
      <div className="relative">
        <SearchIcon className="pointer-events-none absolute start-3 top-1/2 size-5 -translate-y-1/2 text-gray-400" aria-hidden />
        <Input
          type="search"
          aria-label="Search tasks"
          placeholder="Search title and description"
          className="ps-10"
          maxLength={100}
          value={text}
          onChange={(event) => {
            setText(event.target.value)
            pushSearch(event.target.value.trim())
          }}
        />
      </div>

      <div className="grid grid-cols-2 gap-2 sm:flex sm:flex-wrap sm:items-center">
        <Select
          items={statusItems}
          value={filters.status ?? ANY}
          onValueChange={(value) => change({ status: value === ANY ? undefined : (value as Filters['status']) })}
        >
          <SelectTrigger aria-label="Status" size="sm" className="sm:w-40">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            {statusItems.map((item) => (
              <SelectItem key={item.value} value={item.value}>
                {item.label}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>

        <Select
          items={priorityItems}
          value={filters.priority ?? ANY}
          onValueChange={(value) => change({ priority: value === ANY ? undefined : (value as Filters['priority']) })}
        >
          <SelectTrigger aria-label="Priority" size="sm" className="sm:w-40">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            {priorityItems.map((item) => (
              <SelectItem key={item.value} value={item.value}>
                {item.label}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>

        <Select
          items={assigneeItems}
          value={filters.assignee ?? ANY}
          onValueChange={(value) => change({ assignee: value === ANY || value === null ? undefined : String(value) })}
        >
          <SelectTrigger aria-label="Assignee" size="sm" className="sm:w-48">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            {assigneeItems.map((item) => (
              <SelectItem key={item.value} value={item.value}>
                {item.label}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>

        <Select
          items={sortItems}
          value={filters.sort ?? 'newest'}
          onValueChange={(value) => change({ sort: value as Filters['sort'] })}
        >
          <SelectTrigger aria-label="Sort by" size="sm" className="sm:w-40">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            {sortItems.map((item) => (
              <SelectItem key={item.value} value={item.value}>
                {item.label}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>

        <div className="flex items-center gap-2 px-1 sm:px-2">
          <Checkbox
            id="filter-overdue"
            checked={filters.overdue ?? false}
            onCheckedChange={(checked) => change({ overdue: checked === true || undefined })}
          />
          <Label htmlFor="filter-overdue">Overdue</Label>
        </div>

        {hasActiveFilters(filters) && (
          <Button variant="ghost" size="sm" onClick={clear}>
            <XIcon aria-hidden />
            Clear filters
          </Button>
        )}
        {isPending && <Spinner className="size-4 text-gray-400" aria-label="Loading" />}
      </div>
    </div>
  )
}
