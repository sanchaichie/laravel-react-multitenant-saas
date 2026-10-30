import { PlusIcon } from 'lucide-react'
import { ErrorState } from '@/components/ErrorState'
import { Button } from '@/components/ui/button'
import { Card, CardContent } from '@/components/ui/card'
import { Skeleton } from '@/components/ui/skeleton'
import { usePermissions, useRoles } from '@/features/roles/api'
import { RoleFormDialog } from '@/features/roles/RoleFormDialog'
import { RolesTable } from '@/features/roles/RolesTable'
import { useCan } from '@/hooks/useCan'
import { errorMessage } from '@/lib/apiErrors'
import { useCurrentOrganization } from '@/stores/auth'

export function RolesPage() {
  const organization = useCurrentOrganization()
  const roles = useRoles()
  // Labels and groups for the summaries and the form's checkboxes.
  const catalogue = usePermissions()
  const canManage = useCan('roles.manage')

  if (!organization) {
    return (
      <ErrorState
        title="No organization selected"
        description="Choose or create one from the switcher in the sidebar to see its roles."
      />
    )
  }

  // Both queries are needed to draw anything: wait for both, show one error for either.
  const failed = roles.isError ? roles : catalogue.isError ? catalogue : null

  function retry() {
    if (roles.isError) void roles.refetch()
    if (catalogue.isError) void catalogue.refetch()
  }

  return (
    <div className="grid gap-6">
      <title>Roles · React SaaS</title>
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="text-2xl font-semibold">Roles</h1>
          <p className="text-sm text-muted-foreground">What each role in {organization.name} allows.</p>
        </div>
        {canManage && catalogue.data && (
          <RoleFormDialog catalogue={catalogue.data.data}>
            <Button>
              <PlusIcon aria-hidden />
              New role
            </Button>
          </RoleFormDialog>
        )}
      </div>

      <Card>
        <CardContent>
          {failed ? (
            <ErrorState title="Could not load the roles" description={errorMessage(failed.error)}>
              <Button variant="outline" onClick={retry}>
                Retry
              </Button>
            </ErrorState>
          ) : roles.isSuccess && catalogue.isSuccess ? (
            // Never empty: every organization has its Owner role.
            <RolesTable roles={roles.data.data} catalogue={catalogue.data.data} />
          ) : (
            <div className="grid gap-2" aria-label="Loading roles">
              <Skeleton className="h-9 w-full" />
              <Skeleton className="h-9 w-full" />
              <Skeleton className="h-9 w-full" />
            </div>
          )}
        </CardContent>
      </Card>
    </div>
  )
}
