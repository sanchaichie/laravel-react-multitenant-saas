import { useCurrentOrganization } from '@/stores/auth'
import type { Permission } from '@/types/api'

/**
 * Whether the user's role in the current organization grants `permission`.
 * Only for showing or hiding controls: the API checks every request itself.
 * (The `useOrganizationPermissions` composable of the Nuxt app.)
 */
export function useCan(permission: Permission): boolean {
  return useCurrentOrganization()?.permissions.includes(permission) ?? false
}
