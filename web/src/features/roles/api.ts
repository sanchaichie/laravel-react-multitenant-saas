import { queryOptions, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { apiFetch } from '@/lib/api'
import { useCurrentOrganization } from '@/stores/auth'
import type { PermissionInfo, Role, RoleDetails, RolePayload } from '@/types/api'

export const rolesQuery = (organizationId: number) =>
  queryOptions({
    queryKey: ['org', organizationId, 'roles'],
    queryFn: ({ signal }) => apiFetch<{ data: RoleDetails[] }>('/api/roles', { signal }),
  })

/**
 * The permission catalogue. The same for every organization and only changes with
 * a deploy, so it's user-level (not under ['org']) and never goes stale.
 */
export const permissionsQuery = queryOptions({
  queryKey: ['permissions'],
  queryFn: ({ signal }) => apiFetch<{ data: PermissionInfo[] }>('/api/permissions', { signal }),
  staleTime: Infinity,
})

export function useRoles() {
  const organizationId = useCurrentOrganization()?.id
  return useQuery({ ...rolesQuery(organizationId ?? 0), enabled: organizationId !== undefined })
}

export function usePermissions() {
  return useQuery(permissionsQuery)
}

/**
 * Role choices in the API's order: Owner first, then oldest first (the order
 * roles were created in, so Admin and Member come before custom ones).
 */
export function sortRoles<T extends Role>(roles: T[]): T[] {
  return [...roles].sort((a, b) => Number(b.is_owner) - Number(a.is_owner) || a.id - b.id)
}

// A role's permissions change what its members may do, and its name shows on the
// members page: refresh both lists. Your own role can't be changed by you (the API
// refuses), so your permissions (['me']) stay as they are.
function useInvalidateRoles() {
  const queryClient = useQueryClient()

  return (organizationId: number | undefined) =>
    Promise.all([
      queryClient.invalidateQueries({ queryKey: ['org', organizationId, 'roles'] }),
      queryClient.invalidateQueries({ queryKey: ['org', organizationId, 'members'] }),
      queryClient.invalidateQueries({ queryKey: ['org', organizationId, 'invitations'] }),
    ])
}

export function useCreateRole() {
  const organizationId = useCurrentOrganization()?.id
  const invalidate = useInvalidateRoles()

  return useMutation({
    mutationFn: (body: RolePayload) => apiFetch<{ data: RoleDetails }>('/api/roles', { method: 'POST', body }),
    onMutate: () => ({ organizationId }),
    onSuccess: (_data, _body, context) => invalidate(context.organizationId),
  })
}

export function useUpdateRole() {
  const organizationId = useCurrentOrganization()?.id
  const invalidate = useInvalidateRoles()

  return useMutation({
    mutationFn: ({ id, ...body }: RolePayload & { id: number }) =>
      apiFetch<{ data: RoleDetails }>(`/api/roles/${id}`, { method: 'PATCH', body }),
    onMutate: () => ({ organizationId }),
    onSuccess: (_data, _body, context) => invalidate(context.organizationId),
  })
}

export function useDeleteRole() {
  const organizationId = useCurrentOrganization()?.id
  const invalidate = useInvalidateRoles()

  return useMutation({
    mutationFn: (role: RoleDetails) => apiFetch<void>(`/api/roles/${role.id}`, { method: 'DELETE' }),
    onMutate: () => ({ organizationId }),
    onSuccess: (_data, _role, context) => invalidate(context.organizationId),
  })
}
