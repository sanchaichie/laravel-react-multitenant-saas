import { Link } from 'react-router'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { formatDate } from '@/lib/format'
import { useAuth, useCurrentOrganization } from '@/stores/auth'

export function HomePage() {
  // RequireAuth only renders this page once the user is loaded.
  const user = useAuth((s) => s.user)
  const organization = useCurrentOrganization()
  if (!user) {
    return null
  }

  return (
    <>
      <title>Dashboard · React SaaS</title>
      <Card>
        <CardHeader>
          <CardTitle>
            <h1 className="text-2xl font-semibold">Welcome, {user.name}</h1>
          </CardTitle>
          <CardDescription>
            {organization ? (
              <>
                You're working in <span className="font-medium text-foreground">{organization.name}</span> as{' '}
                {organization.role.name}.{' '}
                <Link to="/tasks?assignee=me" className="font-medium text-foreground underline underline-offset-4">
                  See your tasks
                </Link>
              </>
            ) : (
              "No organization selected. Choose or create one from the switcher in the sidebar."
            )}
          </CardDescription>
        </CardHeader>
        <CardContent>
          <dl className="grid gap-x-6 gap-y-2 text-sm sm:grid-cols-[auto_1fr]">
            <dt className="text-muted-foreground">Organization</dt>
            <dd>{organization?.name ?? 'None'}</dd>
            <dt className="text-muted-foreground">Your role</dt>
            <dd>{organization ? organization.role.name : '—'}</dd>
            <dt className="text-muted-foreground">Email</dt>
            <dd className="break-all">{user.email}</dd>
            <dt className="text-muted-foreground">Member since</dt>
            <dd>{formatDate(user.created_at)}</dd>
          </dl>
        </CardContent>
      </Card>
    </>
  )
}
