import { queryOptions, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { apiFetch } from '@/lib/api'
import { useAuth } from '@/stores/auth'
import type { Organization, OrganizationList, OrganizationRole } from '@/types/api'

/**
 * The user's organizations (not org-scoped: it's the same list whichever one is
 * current). queryOptions keeps the key and the fetch together, so useQuery,
 * prefetching and setQueryData all agree on them.
 */
export const organizationsQuery = queryOptions({
  queryKey: ['organizations'],
  queryFn: ({ signal }) => apiFetch<OrganizationList>('/api/organizations', { signal }),
})

export function useOrganizations() {
  return useQuery(organizationsQuery)
}

/**
 * After the server changed the current organization: mirror it in the store and
 * drop every org-scoped query (['org', id, ...]), so no page shows the previous
 * organization's data; the pages refetch for the new one.
 */
function useEnterOrganization() {
  const queryClient = useQueryClient()
  const setCurrentOrganization = useAuth((s) => s.setCurrentOrganization)

  return (organization: Organization) => {
    setCurrentOrganization(organization)
    queryClient.removeQueries({ queryKey: ['org'] })
    void queryClient.invalidateQueries({ queryKey: organizationsQuery.queryKey })
  }
}

export function useSwitchOrganization() {
  const queryClient = useQueryClient()
  const enterOrganization = useEnterOrganization()

  return useMutation({
    // Stop requests for the old organization first, so a late answer can't land
    // in the cache after the switch.
    onMutate: () => queryClient.cancelQueries({ queryKey: ['org'] }),
    // The server checks membership: an organization you're not in is a 404.
    mutationFn: (organizationId: number) =>
      apiFetch<{ data: Organization }>(`/api/organizations/${organizationId}/switch`, { method: 'POST' }),
    onSuccess: ({ data }) => enterOrganization(data),
  })
}

export function useCreateOrganization() {
  const queryClient = useQueryClient()
  const enterOrganization = useEnterOrganization()

  return useMutation({
    mutationFn: (name: string) =>
      apiFetch<{ data: Organization }>('/api/organizations', { method: 'POST', body: { name } }),
    onSuccess: ({ data }) => {
      // Show it in the switcher right away, before the refetch answers.
      queryClient.setQueryData(organizationsQuery.queryKey, (list) =>
        list
          ? { data: [...list.data, data].sort((a, b) => a.name.localeCompare(b.name)), meta: { current_organization_id: data.id } }
          : list,
      )
      enterOrganization(data)
    },
  })
}

const roleLabels: Record<OrganizationRole, string> = { owner: 'Owner', admin: 'Admin', member: 'Member' }

export function roleLabel(role: OrganizationRole): string {
  return roleLabels[role]
}
