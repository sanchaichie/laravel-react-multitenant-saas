import { ArrowDownIcon, ArrowRightIcon, ArrowUpIcon, CircleCheckIcon, CircleDashedIcon, CircleDotIcon } from 'lucide-react'
import { Badge } from '@/components/ui/badge'
import type { TaskPriority, TaskStatus } from '@/types/api'
import { priorityLabel, statusLabel } from './filters'

const statusIcons = { todo: CircleDashedIcon, in_progress: CircleDotIcon, done: CircleCheckIcon }
const statusVariants = { todo: 'outline', in_progress: 'secondary', done: 'default' } as const

export function TaskStatusBadge({ status }: { status: TaskStatus }) {
  const Icon = statusIcons[status]

  return (
    <Badge variant={statusVariants[status]}>
      <Icon aria-hidden data-icon="inline-start" />
      {statusLabel(status)}
    </Badge>
  )
}

const priorityIcons = { low: ArrowDownIcon, medium: ArrowRightIcon, high: ArrowUpIcon }

export function TaskPriorityBadge({ priority }: { priority: TaskPriority }) {
  const Icon = priorityIcons[priority]

  return (
    <span className={`inline-flex items-center gap-1 text-sm ${priority === 'high' ? 'font-medium' : 'text-muted-foreground'}`}>
      <Icon className="size-3.5" aria-hidden />
      {priorityLabel(priority)}
    </span>
  )
}

export function OverdueBadge() {
  return <Badge variant="destructive">Overdue</Badge>
}
