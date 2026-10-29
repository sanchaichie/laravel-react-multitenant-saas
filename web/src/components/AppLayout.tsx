import { LogOutIcon } from 'lucide-react'
import { Link, Outlet } from 'react-router'
import { Button } from '@/components/ui/button'
import { Spinner } from '@/components/ui/spinner'
import { useLogout } from '@/features/auth/api'
import { useAuth } from '@/stores/auth'

/** Layout route for the logged-in pages: a top bar, then the page. The sidebar comes with organizations. */
export function AppLayout() {
  const user = useAuth((s) => s.user)
  const logout = useLogout()

  return (
    <div className="min-h-svh bg-background">
      <header className="border-b">
        <div className="mx-auto flex h-14 max-w-5xl items-center justify-between gap-4 px-4">
          <Link to="/" className="font-semibold">
            React SaaS
          </Link>
          <div className="flex min-w-0 items-center gap-3">
            <span className="truncate text-sm text-muted-foreground">{user?.name}</span>
            <Button variant="outline" size="sm" onClick={() => logout.mutate()} disabled={logout.isPending}>
              {logout.isPending ? <Spinner /> : <LogOutIcon />}
              Log out
            </Button>
          </div>
        </div>
      </header>
      <main className="mx-auto max-w-5xl px-4 py-8">
        <Outlet />
      </main>
    </div>
  )
}
