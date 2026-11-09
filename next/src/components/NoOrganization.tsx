import { Card } from '@/components/Card'
import { EmptyState } from '@/components/EmptyState'

/** The user has no current organization (they left the last one): nothing org-scoped to show. */
export function NoOrganization() {
  return (
    <Card>
      <EmptyState
        title="No organization selected"
        description="Choose one from the switcher at the top, or create one in the React app."
      />
    </Card>
  )
}
