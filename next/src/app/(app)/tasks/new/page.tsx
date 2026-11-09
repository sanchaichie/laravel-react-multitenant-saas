import type { Metadata } from 'next'
import { Card } from '@/components/Card'
import { EmptyState } from '@/components/EmptyState'
import { NoOrganization } from '@/components/NoOrganization'
import { PageHeader } from '@/components/PageHeader'
import { TaskForm } from '@/features/tasks/TaskForm'
import { requireUser } from '@/lib/dal'
import { getMembers } from '@/lib/tasks'

export const metadata: Metadata = { title: 'New task' }

export default async function NewTaskPage() {
  const user = await requireUser()
  const organization = user.current_organization
  if (!organization) {
    return <NoOrganization />
  }
  if (!organization.permissions.includes('tasks.create')) {
    return (
      <Card>
        <EmptyState title="You can't create tasks" description={`Your role in ${organization.name} can only read them.`} />
      </Card>
    )
  }

  const members = await getMembers()

  return (
    <>
      <PageHeader title="New task" description={`In ${organization.name}.`} />
      <Card className="p-5 sm:p-6">
        <TaskForm organizationId={organization.id} members={members} />
      </Card>
    </>
  )
}
