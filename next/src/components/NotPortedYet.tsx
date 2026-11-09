import type { Metadata } from 'next'
import Link from 'next/link'
import { AuthPanel } from '@/components/AuthPanel'
import { FormMessage } from '@/components/FormMessage'

export const notPortedMetadata: Metadata = { title: 'Not in the Next.js app yet' }

/**
 * An email link (invitation, email change, two-factor undo) whose page only the
 * React app has so far. The API sends links to whichever frontend FRONTEND_URL names.
 */
export function NotPortedYet() {
  return (
    <AuthPanel title="Open this link in the React app">
      <div className="space-y-4">
        <FormMessage
          variant="info"
          message="This page isn't in the Next.js frontend yet. Run the React frontend (FRONTEND=react) and open the link from the email again."
        />
        <Link href="/" className="text-sm text-brand-500 hover:text-brand-600 dark:text-brand-400">
          Go to the overview
        </Link>
      </div>
    </AuthPanel>
  )
}
