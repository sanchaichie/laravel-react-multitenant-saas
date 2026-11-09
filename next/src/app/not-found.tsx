import Link from 'next/link'
import { EmptyState } from '@/components/EmptyState'
import { buttonVariants } from '@/components/ui/button'

export default function NotFound() {
  return (
    <div className="flex min-h-screen items-center justify-center bg-white p-6 dark:bg-gray-900">
      <EmptyState title="Page not found" description="The address may be mistyped, or the page has moved.">
        <Link href="/" className={buttonVariants({ variant: 'outline' })}>
          Go to the overview
        </Link>
      </EmptyState>
    </div>
  )
}
