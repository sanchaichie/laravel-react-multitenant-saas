import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { formatDate } from '@/lib/format'
import { useAuth } from '@/stores/auth'

export function HomePage() {
  // RequireAuth only renders this page once the user is loaded.
  const user = useAuth((s) => s.user)
  if (!user) {
    return null
  }

  return (
    <>
      <title>Home · React SaaS</title>
      <Card>
        <CardHeader>
          <CardTitle>
            <h1 className="text-2xl font-semibold">Welcome, {user.name}</h1>
          </CardTitle>
          <CardDescription>You're logged in. Organizations and tasks come next.</CardDescription>
        </CardHeader>
        <CardContent>
          <dl className="grid gap-x-6 gap-y-2 text-sm sm:grid-cols-[auto_1fr]">
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
