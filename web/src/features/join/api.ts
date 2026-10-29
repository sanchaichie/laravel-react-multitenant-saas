import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { reloadUser } from '@/features/auth/session'
import { organizationsQuery } from '@/features/organizations/api'
import { ApiError, apiFetch } from '@/lib/api'
import type { InvitationPreview, Organization } from '@/types/api'

/** The invitation behind a /join link: user-level, not org-scoped (you're not in it yet). */
export function useInvitationPreview(token: string) {
  return useQuery({
    queryKey: ['invitation', token],
    queryFn: ({ signal }) => apiFetch<{ data: InvitationPreview }>(`/api/join/${encodeURIComponent(token)}`, { signal }),
    // 404 (no longer valid) and 403 (another account) won't change by asking again.
    retry: (count, error) => !(error instanceof ApiError && error.status < 500) && count < 1,
  })
}

export function useAcceptInvitation(token: string) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: () => apiFetch<{ data: Organization }>(`/api/join/${encodeURIComponent(token)}`, { method: 'POST' }),
    onSuccess: async () => {
      // Now in the new organization (and verified): reload the user, and drop the
      // previous organization's data.
      await reloadUser()
      queryClient.removeQueries({ queryKey: ['org'] })
      queryClient.removeQueries({ queryKey: ['invitation', token] })
      await queryClient.invalidateQueries({ queryKey: organizationsQuery.queryKey })
    },
  })
}
