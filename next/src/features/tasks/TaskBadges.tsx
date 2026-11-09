import type { TaskPriority, TaskStatus } from '@shared/types/api'
import { badgeVariants } from '@/components/ui/badge'
import { ArrowDownIcon, ArrowRightIcon, ArrowUpIcon } from '@/icons'
import { cn } from '@/utils'
import { priorityLabel, statusLabel } from './filters'

// Plain spans with TailAdmin's badge classes (badgeVariants), so Server Components
// can render them without shipping the Base UI badge to the browser.

const statusColors = { todo: 'gray', in_progress: 'warning', done: 'success' } as const

export function TaskStatusBadge({ status }: { status: TaskStatus }) {
  return <span className={badgeVariants({ variant: 'light', color: statusColors[status], size: 'sm' })}>{statusLabel(status)}</span>
}

const priorityIcons = { low: ArrowDownIcon, medium: ArrowRightIcon, high: ArrowUpIcon }

export function TaskPriorityBadge({ priority }: { priority: TaskPriority }) {
  const Icon = priorityIcons[priority]

  return (
    <span
      className={cn(
        'inline-flex items-center gap-1 text-sm',
        priority === 'high' ? 'font-medium text-gray-800 dark:text-white/90' : 'text-gray-500 dark:text-gray-400',
      )}
    >
      <Icon className="size-3.5" aria-hidden />
      {priorityLabel(priority)}
    </span>
  )
}

export function OverdueBadge() {
  return <span className={badgeVariants({ variant: 'light', color: 'error', size: 'sm' })}>Overdue</span>
}
