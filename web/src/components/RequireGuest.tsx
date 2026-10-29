import { useEffect } from 'react'
import { Navigate, Outlet, useSearchParams } from 'react-router'
import { chooseOrganizationTarget } from '@/features/organizations/afterLogin'
import { safeRedirect } from '@/lib/safeRedirect'
import { selectIsLoggedIn, useAuth } from '@/stores/auth'

/**
 * Wraps the login and register pages (the Nuxt `guest` middleware). Once the
 * user is logged in, including right after the form stores the session, it
 * sends them to ?redirect= (paths on this site only) or home; first to the
 * organization picker when login found more than one organization.
 */
export function RequireGuest() {
  const isLoggedIn = useAuth(selectIsLoggedIn)
  const chooseOrganization = useAuth((s) => s.chooseOrganization)
  const organizationChosen = useAuth((s) => s.organizationChosen)
  const [searchParams] = useSearchParams()

  const redirect = safeRedirect(searchParams.get('redirect'))
  const target = isLoggedIn && chooseOrganization ? chooseOrganizationTarget(redirect) : redirect
  const skipsPicker = isLoggedIn && chooseOrganization && !target.startsWith('/select-organization')

  // The picker clears the question when it opens; when we go past it (an
  // invitation link), clear it here, or a later visit to /login would ask again.
  useEffect(() => {
    if (skipsPicker) {
      organizationChosen()
    }
  }, [skipsPicker, organizationChosen])

  if (isLoggedIn) {
    return <Navigate to={target} replace />
  }

  return <Outlet />
}
