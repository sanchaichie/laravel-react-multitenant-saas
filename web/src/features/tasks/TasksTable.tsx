import { Link } from 'react-router'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { formatDate } from '@/lib/format'
import type { Task } from '@/types/api'
import { OverdueBadge, TaskPriorityBadge, TaskStatusBadge } from './TaskBadges'

export function TasksTable({ tasks }: { tasks: Task[] }) {
  return (
    <Table>
      <TableHeader>
        <TableRow>
          <TableHead>Task</TableHead>
          <TableHead>Status</TableHead>
          <TableHead className="hidden md:table-cell">Priority</TableHead>
          <TableHead className="hidden sm:table-cell">Assignee</TableHead>
          <TableHead className="hidden sm:table-cell">Due</TableHead>
        </TableRow>
      </TableHeader>
      <TableBody>
        {tasks.map((task) => (
          <TableRow key={task.id}>
            <TableCell className="max-w-0 w-full">
              <Link to={`/tasks/${task.id}`} className="block truncate font-medium underline-offset-4 hover:underline">
                {task.title}
              </Link>
              {/* Below sm the assignee and due date fold under the title. */}
              <p className="truncate text-xs text-muted-foreground sm:hidden">
                {task.assignee?.name ?? 'Unassigned'}
                {task.due_date && ` · due ${formatDate(task.due_date)}`}
                {task.is_overdue && <span className="text-destructive"> · overdue</span>}
              </p>
            </TableCell>
            <TableCell>
              <TaskStatusBadge status={task.status} />
            </TableCell>
            <TableCell className="hidden md:table-cell">
              <TaskPriorityBadge priority={task.priority} />
            </TableCell>
            <TableCell className="hidden sm:table-cell">
              {task.assignee?.name ?? <span className="text-muted-foreground">Unassigned</span>}
            </TableCell>
            <TableCell className="hidden whitespace-nowrap sm:table-cell">
              {task.due_date ? (
                <span className="inline-flex items-center gap-2">
                  {formatDate(task.due_date)}
                  {task.is_overdue && <OverdueBadge />}
                </span>
              ) : (
                <span className="text-muted-foreground">—</span>
              )}
            </TableCell>
          </TableRow>
        ))}
      </TableBody>
    </Table>
  )
}
