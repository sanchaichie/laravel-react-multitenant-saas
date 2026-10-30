import { useNavigate, useParams } from 'react-router'
import { toast } from 'sonner'
import { ErrorState } from '@/components/ErrorState'
import { Button } from '@/components/ui/button'
import { Spinner } from '@/components/ui/spinner'
import { useLogout } from '@/features/auth/api'
import { AuthCard } from '@/features/auth/AuthCard'
import { useAcceptInvitation, useInvitationPreview } from '@/features/join/api'
import { ApiError } from '@/lib/api'
import { errorMessage } from '@/lib/apiErrors'
import { formatDate } from '@/lib/format'
import { useAuth } from '@/stores/auth'

/**
 * The link from an invitation email. Logged out, RequireAuth sends you to log in
 * (or register: the login page links to it with the same ?redirect=) and back here.
 */
export function JoinPage() {
  // useParams ↔ useRoute().params: the :token part of /join/:token.
  const { token = '' } = useParams()
  const preview = useInvitationPreview(token)
  const accept = useAcceptInvitation(token)
  const logout = useLogout()
  const email = useAuth((s) => s.user?.email)
  const navigate = useNavigate()

  function onAccept() {
    accept.mutate(undefined, {
      onSuccess: ({ data }) => {
        toast.success(`You joined ${data.name}`, { description: "It's now your current organization." })
        void navigate('/', { replace: true })
      },
      onError: (error) => toast.error('Could not accept the invitation', { description: errorMessage(error) }),
    })
  }

  if (preview.isPending) {
    return (
      <AuthCard title="Invitation">
        <div className="flex justify-center py-6">
          <Spinner className="size-6 text-muted-foreground" />
        </div>
      </AuthCard>
    )
  }

  if (preview.isError) {
    const status = preview.error instanceof ApiError ? preview.error.status : null

    // Logged in as someone other than the invited address.
    if (status === 403) {
      return (
        <AuthCard title="Invitation">
          <title>Invitation · React SaaS</title>
          <ErrorState
            title="This invitation is for another email address"
            description={`You're logged in as ${email ?? 'another account'}. Log out, then log in or register with the address the invitation was sent to.`}
          >
            <Button onClick={() => logout.mutate(`/login?redirect=${encodeURIComponent(`/join/${token}`)}`)} disabled={logout.isPending}>
              Log out and switch account
            </Button>
          </ErrorState>
        </AuthCard>
      )
    }

    return (
      <AuthCard title="Invitation">
        <title>Invitation · React SaaS</title>
        <ErrorState
          title={status === 404 ? 'This invitation is no longer valid' : 'Could not load the invitation'}
          description={
            status === 404
              ? 'It may have expired, been revoked or been used already. Ask for a new one.'
              : errorMessage(preview.error)
          }
        >
          <Button variant="outline" onClick={() => void navigate('/')}>
            Go home
          </Button>
        </ErrorState>
      </AuthCard>
    )
  }

  const invitation = preview.data.data

  return (
    <AuthCard
      title={`Join ${invitation.organization.name}`}
      description={`${invitation.invited_by ?? 'Someone'} invited you to join with the ${invitation.role.name} role.`}
    >
      <title>{`Join ${invitation.organization.name} · React SaaS`}</title>
      <div className="grid gap-4">
        <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-sm">
          <dt className="text-muted-foreground">For</dt>
          <dd className="break-all">{invitation.email}</dd>
          <dt className="text-muted-foreground">Expires</dt>
          <dd>{formatDate(invitation.expires_at)}</dd>
        </dl>
        {invitation.already_member && (
          <p className="text-sm text-muted-foreground">
            You're already a member. Accepting keeps your current role and switches you to it.
          </p>
        )}
        <Button onClick={onAccept} disabled={accept.isPending}>
          {accept.isPending && <Spinner />}
          Accept invitation
        </Button>
      </div>
    </AuthCard>
  )
}
