import { ErrorState } from '@/components/ErrorState'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Skeleton } from '@/components/ui/skeleton'
import { useMembers } from '@/features/members/api'
import { InviteForm } from '@/features/members/InviteForm'
import { MembersTable } from '@/features/members/MembersTable'
import { PendingInvitations } from '@/features/members/PendingInvitations'
import { useCan } from '@/hooks/useCan'
import { errorMessage } from '@/lib/apiErrors'
import { useCurrentOrganization } from '@/stores/auth'

export function MembersPage() {
  const organization = useCurrentOrganization()
  const members = useMembers()
  const canInvite = useCan('members.invite')

  if (!organization) {
    return (
      <ErrorState
        title="No organization selected"
        description="Choose or create one from the switcher in the sidebar to see its members."
      />
    )
  }

  return (
    <div className="grid gap-6">
      <title>Members · React SaaS</title>
      <div>
        <h1 className="text-2xl font-semibold">Members</h1>
        <p className="text-sm text-muted-foreground">The people in {organization.name}.</p>
      </div>

      <Card>
        <CardContent>
          {members.isPending ? (
            <div className="grid gap-2" aria-label="Loading members">
              <Skeleton className="h-9 w-full" />
              <Skeleton className="h-9 w-full" />
              <Skeleton className="h-9 w-full" />
            </div>
          ) : members.isError ? (
            <ErrorState title="Could not load the members" description={errorMessage(members.error)}>
              <Button variant="outline" onClick={() => void members.refetch()}>
                Retry
              </Button>
            </ErrorState>
          ) : (
            <MembersTable members={members.data.data} />
          )}
        </CardContent>
      </Card>

      {canInvite && (
        <>
          <Card>
            <CardHeader>
              <CardTitle>
                <h2>Invite someone</h2>
              </CardTitle>
              <CardDescription>They get an email with a link to join. It works for 7 days.</CardDescription>
            </CardHeader>
            <CardContent>
              {/* key: a new form (role back to Member) for each organization. */}
              <InviteForm key={organization.id} />
            </CardContent>
          </Card>

          <Card>
            <CardHeader>
              <CardTitle>
                <h2>Pending invitations</h2>
              </CardTitle>
            </CardHeader>
            <CardContent>
              <PendingInvitations />
            </CardContent>
          </Card>
        </>
      )}
    </div>
  )
}
