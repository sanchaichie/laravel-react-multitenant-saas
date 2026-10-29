import { Outlet } from 'react-router'

/** Layout route for the logged-out pages: a centered column. <Outlet> is where the page renders. */
export function AuthLayout() {
  return (
    <div className="flex min-h-svh flex-col items-center justify-center gap-6 bg-muted px-4 py-10">
      <p className="text-lg font-semibold">React SaaS</p>
      <Outlet />
    </div>
  )
}
