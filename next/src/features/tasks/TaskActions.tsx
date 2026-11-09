'use client'

import { useState, useTransition } from 'react'
import { useRouter } from 'next/navigation'
import { toast } from 'sonner'
import type { Task, TaskStatus } from '@shared/types/api'
import { Button } from '@/components/ui/button'
import { Dialog, DialogClose, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Spinner } from '@/components/ui/spinner'
import { TrashBinIcon } from '@/icons'
import { type ActionResult, deleteTask, setTaskStatus } from './actions'
import { TASK_STATUSES, statusLabel } from './filters'

/** Says why an action failed; when another tab switched organization, reloads for the new one. */
function useReportFailure() {
  const router = useRouter()

  return (result: ActionResult, title: string) => {
    if (result.organizationChanged) {
      toast.info('Your organization changed', { description: 'It was switched in another tab.' })
      router.replace('/tasks')
      router.refresh()
      return
    }
    toast.error(title, { description: result.message })
  }
}

/** Delete, after a confirmation. On success the action redirects to the list. */
export function DeleteTaskButton({ organizationId, task }: { organizationId: number; task: Task }) {
  const [open, setOpen] = useState(false)
  const [isPending, startTransition] = useTransition()
  const reportFailure = useReportFailure()

  function onConfirm() {
    startTransition(async () => {
      const result = await deleteTask(organizationId, task.id)
      // Only a failure comes back: success redirects to /tasks.
      if (result) {
        setOpen(false)
        reportFailure(result, 'Could not delete the task')
      }
    })
  }

  return (
    <Dialog open={open} onOpenChange={setOpen}>
      <Button variant="outline" onClick={() => setOpen(true)} disabled={isPending}>
        <TrashBinIcon aria-hidden />
        Delete
      </Button>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Delete this task?</DialogTitle>
          <DialogDescription>
            <span className="font-medium text-gray-800 dark:text-white/90">{task.title}</span> will be deleted for everyone. This
            can&apos;t be undone.
          </DialogDescription>
        </DialogHeader>
        <DialogFooter>
          <DialogClose render={<Button variant="outline" />}>Cancel</DialogClose>
          <Button variant="destructive" onClick={onConfirm} disabled={isPending}>
            {isPending && <Spinner />}
            Delete
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}

/**
 * Changes the status at once. Controlled by the server's value: after the action,
 * router.refresh() re-renders the page on the server and the new status comes in
 * as a prop (no client cache to update by hand).
 */
export function StatusSelect({ organizationId, task }: { organizationId: number; task: Task }) {
  const router = useRouter()
  const [isPending, startTransition] = useTransition()
  const reportFailure = useReportFailure()
  const items = TASK_STATUSES.map((value) => ({ value, label: statusLabel(value) }))

  function onChange(status: TaskStatus) {
    startTransition(async () => {
      const result = await setTaskStatus(organizationId, task.id, status)
      if (result.ok) {
        toast.success(`Marked ${statusLabel(status).toLowerCase()}`)
        router.refresh()
      } else {
        reportFailure(result, 'Could not change the status')
      }
    })
  }

  return (
    <Select items={items} value={task.status} onValueChange={(value) => onChange(value as TaskStatus)} disabled={isPending}>
      <SelectTrigger aria-label="Status" size="sm" className="w-40">
        <SelectValue />
      </SelectTrigger>
      <SelectContent>
        {items.map((item) => (
          <SelectItem key={item.value} value={item.value}>
            {item.label}
          </SelectItem>
        ))}
      </SelectContent>
    </Select>
  )
}
