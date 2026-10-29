import { useEffect } from 'react'
import { ChevronRightIcon } from 'lucide-react'
import { Navigate, useNavigate, useSearchParams } from 'react-router'
import { toast } from 'sonner'
import { ErrorState } from '@/components/ErrorState'
import { Button } from '@/components/ui/button'
import { Spinner } from '@/components/ui/spinner'
import { useLogout } from '@/features/auth/api'
import { AuthCard } from '@/features/auth/AuthCard'
import { roleLabel, useOrganizations, useSwitchOrganization } from '@/features/organizations/api'
import { errorMessage } from '@/lib/apiErrors'
import { safeRedirect } from '@/lib/safeRedirect'
import { useAuth, useCurrentOrganization } from '@/stores/auth'

/** "Choose an organization", right after logging in with more than one. */
export function SelectOrganizationPage() {
  const [searchParams] = useSearchParams()
  // The picker itself as the target would bring us straight back here.
  const target = safeRedirect(searchParams.get('redirect'))
  const redirect = target.startsWith('/select-organization') ? '/' : target

  const current = useCurrentOrganization()
  const organizations = useOrganizations()
  const switchOrganization = useSwitchOrganization({ toSectionRoot: false })
  const organizationChosen = useAuth((s) => s.organizationChosen)
  const logout = useLogout()
  const navigate = useNavigate()

  // We're here now: the guard no longer needs to send us (a store update is a
  // side effect, so it goes in an effect, not in the render).
  useEffect(() => organizationChosen(), [organizationChosen])

  function choose(organizationId: number) {
    if (organizationId === current?.id) {
      void navigate(redirect, { replace: true })
      return
    }

    switchOrganization.mutate(organizationId, {
      onSuccess: () => void navigate(redirect, { replace: true }),
      onError: (error) => {
        toast.error('Could not switch organization', { description: errorMessage(error) })
        void organizations.refetch()
      },
    })
  }

  if (organizations.isPending) {
    return (
      <AuthCard title="Choose an organization">
        <div className="flex justify-center py-6">
          <Spinner className="size-6 text-muted-foreground" />
        </div>
      </AuthCard>
    )
  }

  if (organizations.isError) {
    return (
      <AuthCard title="Choose an organization">
        <ErrorState title="Could not load your organizations" description={errorMessage(organizations.error)}>
          <Button onClick={() => void organizations.refetch()}>Retry</Button>
          <Button variant="outline" onClick={() => void navigate(redirect, { replace: true })}>
            Continue
          </Button>
        </ErrorState>
      </AuthCard>
    )
  }

  // Nothing to choose (or the URL was opened directly): carry on.
  if (organizations.data.data.length <= 1) {
    return <Navigate to={redirect} replace />
  }

  return (
    <>
      <title>Choose an organization · React SaaS</title>
      <AuthCard
        title="Choose an organization"
        description="You're a member of several. Pick the one to work in; you can switch any time from the sidebar."
        footer={
          <Button variant="link" className="h-auto p-0" onClick={() => logout.mutate()} disabled={logout.isPending}>
            Log in with another account
          </Button>
        }
      >
        <ul className="grid gap-2">
          {organizations.data.data.map((organization) => {
            const isCurrent = organization.id === current?.id
            const isSwitchingHere = switchOrganization.isPending && switchOrganization.variables === organization.id

            return (
              <li key={organization.id}>
                <Button
                  variant="outline"
                  className="h-auto w-full justify-between gap-3 px-3 py-2.5 text-left"
                  disabled={switchOrganization.isPending}
                  onClick={() => choose(organization.id)}
                >
                  <span className="grid min-w-0">
                    <span className="truncate font-medium">{organization.name}</span>
                    <span className="text-xs font-normal text-muted-foreground">
                      {roleLabel(organization.role)}
                      {isCurrent && ' · Last used'}
                    </span>
                  </span>
                  {isSwitchingHere ? <Spinner /> : <ChevronRightIcon aria-hidden />}
                </Button>
              </li>
            )
          })}
        </ul>
      </AuthCard>
    </>
  )
}
