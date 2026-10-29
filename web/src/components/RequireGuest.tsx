import { Navigate, Outlet, useSearchParams } from 'react-router'
import { safeRedirect } from '@/lib/safeRedirect'
import { selectIsLoggedIn, useAuth } from '@/stores/auth'

/**
 * Wraps the login and register pages (the Nuxt `guest` middleware). Once the
 * user is logged in, including right after the form stores the session, it
 * sends them to ?redirect= (paths on this site only) or home.
 */
export function RequireGuest() {
  const isLoggedIn = useAuth(selectIsLoggedIn)
  const [searchParams] = useSearchParams()

  if (isLoggedIn) {
    return <Navigate to={safeRedirect(searchParams.get('redirect'))} replace />
  }

  return <Outlet />
}
