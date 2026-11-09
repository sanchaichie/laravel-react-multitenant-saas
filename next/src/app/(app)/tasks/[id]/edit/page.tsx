import type { Metadata } from 'next'
import { notFound } from 'next/navigation'
import { Card } from '@/components/Card'
import { EmptyState } from '@/components/EmptyState'
import { NoOrganization } from '@/components/NoOrganization'
import { PageHeader } from '@/components/PageHeader'
import { parseTaskId } from '@/features/tasks/filters'
import { TaskForm } from '@/features/tasks/TaskForm'
import { requireUser } from '@/lib/dal'
import { getMembers, getTask } from '@/lib/tasks'

export const metadata: Metadata = { title: 'Edit task' }

export default async function EditTaskPage({ params }: PageProps<'/tasks/[id]/edit'>) {
  const { id: rawId } = await params
  const user = await requireUser()
  const organization = user.current_organization
  if (!organization) {
    return <NoOrganization />
  }

  const id = parseTaskId(rawId)
  const [task, members] = await Promise.all([id === null ? null : getTask(id), getMembers()])
  if (!task) {
    notFound()
  }
  // The API decides who may edit (can.update); it checks again on save.
  if (!task.can.update) {
    return (
      <Card>
        <EmptyState title="You can't edit this task" description="Only its creator, its assignee, and people who manage tasks can." />
      </Card>
    )
  }

  return (
    <>
      <PageHeader title="Edit task" description={task.title} />
      <Card className="p-5 sm:p-6">
        {/* key: a fresh form for each task (its fields start from that task). */}
        <TaskForm key={task.id} organizationId={organization.id} members={members} task={task} writingHelp={task.can.write_with_ai} />
      </Card>
    </>
  )
}
