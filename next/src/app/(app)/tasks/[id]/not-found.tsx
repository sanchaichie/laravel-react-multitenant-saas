import Link from 'next/link'
import { Card } from '@/components/Card'
import { EmptyState } from '@/components/EmptyState'
import { buttonVariants } from '@/components/ui/button'

/** notFound() in the task pages renders this, inside the app layout. */
export default function TaskNotFound() {
  return (
    <Card>
      <EmptyState title="Task not found" description="It may have been deleted, or it belongs to another organization.">
        <Link href="/tasks" className={buttonVariants({ variant: 'outline' })}>
          Back to tasks
        </Link>
      </EmptyState>
    </Card>
  )
}
