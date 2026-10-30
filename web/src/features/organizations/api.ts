import { queryOptions, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useLocation, useNavigate } from 'react-router'
import { apiFetch } from '@/lib/api'
import { useAuth } from '@/stores/auth'
import type { Organization, OrganizationList } from '@/types/api'

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
 *
 * With `toSectionRoot` it also goes to the root of the section you're in
 * (/tasks/42?assignee=7 → /tasks): a record id or a filter belongs to the
 * organization you just left. The store changes first, so nothing is requested
 * from the new organization with the old one's header (a 409); at most one list
 * request goes out with the old filters before the URL catches up.
 */
function useEnterOrganization({ toSectionRoot }: { toSectionRoot: boolean }) {
  const queryClient = useQueryClient()
  const setCurrentOrganization = useAuth((s) => s.setCurrentOrganization)
  const location = useLocation()
  const navigate = useNavigate()

  return (organization: Organization) => {
    setCurrentOrganization(organization)
    queryClient.removeQueries({ queryKey: ['org'] })
    void queryClient.invalidateQueries({ queryKey: organizationsQuery.queryKey })

    const sectionRoot = `/${location.pathname.split('/')[1] ?? ''}`
    if (toSectionRoot && (location.pathname !== sectionRoot || location.search !== '')) {
      void navigate(sectionRoot, { replace: true })
    }
  }
}

/** `toSectionRoot: false` when the caller navigates itself (choosing an organization after login). */
export function useSwitchOrganization({ toSectionRoot = true }: { toSectionRoot?: boolean } = {}) {
  const queryClient = useQueryClient()
  const enterOrganization = useEnterOrganization({ toSectionRoot })

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
  const enterOrganization = useEnterOrganization({ toSectionRoot: true })

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
