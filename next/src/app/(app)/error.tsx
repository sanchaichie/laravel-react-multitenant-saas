'use client'

import { Card } from '@/components/Card'
import { EmptyState } from '@/components/EmptyState'
import { Button } from '@/components/ui/button'
import { AlertIcon } from '@/icons'

/**
 * error.tsx ↔ web's per-query error states: Next.js wraps each page in an error
 * boundary, and anything a Server Component throws (API down, a 500) lands here.
 * reset() tries rendering the page again. (Error messages from the server are
 * hidden in production, so this shows a fixed text.)
 */
export default function AppError({ reset }: { error: Error & { digest?: string }; reset: () => void }) {
  return (
    <Card>
      <EmptyState
        role="alert"
        icon={<AlertIcon />}
        title="Could not load this page"
        description="The server didn't answer as expected. Check that the API is running, then try again."
      >
        <Button variant="outline" onClick={reset}>
          Retry
        </Button>
      </EmptyState>
    </Card>
  )
}
