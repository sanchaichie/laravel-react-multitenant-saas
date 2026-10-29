import { type ReactNode, useState } from 'react'
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog'
import type { Task } from '@/types/api'
import { TaskForm } from './TaskForm'

interface TaskFormDialogProps {
  /** The button that opens it. As the dialog's trigger, it gets focus back on close. */
  children: ReactNode
  task?: Task
  onSaved?: (task: Task) => void
}

/** New task, or Edit task when given one. */
export function TaskFormDialog({ children, task, onSaved }: TaskFormDialogProps) {
  const [open, setOpen] = useState(false)

  return (
    <Dialog open={open} onOpenChange={setOpen}>
      <DialogTrigger asChild>{children}</DialogTrigger>
      <DialogContent className="sm:max-w-lg">
        <DialogHeader>
          <DialogTitle>{task ? 'Edit task' : 'New task'}</DialogTitle>
          <DialogDescription>
            {task ? 'Change any field, then save.' : 'Only a title is needed; everything else can wait.'}
          </DialogDescription>
        </DialogHeader>
        {/* DialogContent unmounts when closed, so each opening gets a fresh form
            filled from the task as it is now. */}
        <TaskForm
          task={task}
          onSaved={(saved) => {
            setOpen(false)
            onSaved?.(saved)
          }}
          onCancel={() => setOpen(false)}
        />
      </DialogContent>
    </Dialog>
  )
}
