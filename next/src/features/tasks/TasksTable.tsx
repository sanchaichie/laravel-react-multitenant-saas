import Link from 'next/link'
import type { Task } from '@shared/types/api'
import { formatDate } from '@/lib/format'
import { OverdueBadge, TaskPriorityBadge, TaskStatusBadge } from './TaskBadges'

const th = 'px-4 py-3 text-start text-xs font-medium text-gray-500 uppercase dark:text-gray-400'
const td = 'px-4 py-3 text-sm text-gray-700 dark:text-gray-300'

/** The task rows (a Server Component: plain HTML, no JavaScript). */
export function TasksTable({ tasks }: { tasks: Task[] }) {
  return (
    <div className="overflow-x-auto">
      <table className="min-w-full">
        <thead className="border-b border-gray-100 dark:border-gray-800">
          <tr>
            <th className={th}>Task</th>
            <th className={th}>Status</th>
            <th className={`${th} hidden md:table-cell`}>Priority</th>
            <th className={`${th} hidden sm:table-cell`}>Assignee</th>
            <th className={`${th} hidden sm:table-cell`}>Due</th>
          </tr>
        </thead>
        <tbody className="divide-y divide-gray-100 dark:divide-gray-800">
          {tasks.map((task) => (
            <tr key={task.id}>
              <td className={`${td} w-full max-w-0`}>
                <Link
                  href={`/tasks/${task.id}`}
                  className="block truncate font-medium text-gray-800 hover:text-brand-500 dark:text-white/90 dark:hover:text-brand-400"
                >
                  {task.title}
                </Link>
                {/* Below sm the assignee and due date fold under the title. */}
                <p className="truncate text-xs text-gray-500 sm:hidden dark:text-gray-400">
                  {task.assignee?.name ?? 'Unassigned'}
                  {task.due_date && ` · due ${formatDate(task.due_date)}`}
                  {task.is_overdue && <span className="text-red-500"> · overdue</span>}
                </p>
              </td>
              <td className={td}>
                <TaskStatusBadge status={task.status} />
              </td>
              <td className={`${td} hidden md:table-cell`}>
                <TaskPriorityBadge priority={task.priority} />
              </td>
              <td className={`${td} hidden whitespace-nowrap sm:table-cell`}>
                {task.assignee?.name ?? <span className="text-gray-400">Unassigned</span>}
              </td>
              <td className={`${td} hidden whitespace-nowrap sm:table-cell`}>
                {task.due_date ? (
                  <span className="inline-flex items-center gap-2">
                    {formatDate(task.due_date)}
                    {task.is_overdue && <OverdueBadge />}
                  </span>
                ) : (
                  <span className="text-gray-400">—</span>
                )}
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}
