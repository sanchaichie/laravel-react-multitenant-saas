import { queryOptions, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useNavigate } from 'react-router'
import { toast } from 'sonner'
import { reloadUser } from '@/features/auth/session'
import { organizationsQuery } from '@/features/organizations/api'
import { apiFetch } from '@/lib/api'
import { useCurrentOrganization } from '@/stores/auth'
import type { Invitation, InvitationList, Member, OrganizationRole } from '@/types/api'

// Org-scoped: the organization id is part of every key, so a switch (which also
// removes ['org'] queries) can never show the previous organization's people.

export const membersQuery = (organizationId: number) =>
  queryOptions({
    queryKey: ['org', organizationId, 'members'],
    queryFn: ({ signal }) => apiFetch<{ data: Member[] }>('/api/members', { signal }),
  })

export const invitationsQuery = (organizationId: number) =>
  queryOptions({
    queryKey: ['org', organizationId, 'invitations'],
    queryFn: ({ signal }) => apiFetch<InvitationList>('/api/invitations', { signal }),
  })

export function useMembers() {
  const organizationId = useCurrentOrganization()?.id
  // `enabled`: no request until there is an organization (the key needs its id).
  return useQuery({ ...membersQuery(organizationId ?? 0), enabled: organizationId !== undefined })
}

/** Pending invitations, and the roles you may invite with (only for members.invite: MembersPage checks). */
export function useInvitations() {
  const organizationId = useCurrentOrganization()?.id
  return useQuery({ ...invitationsQuery(organizationId ?? 0), enabled: organizationId !== undefined })
}

export function useChangeRole() {
  const queryClient = useQueryClient()
  const organizationId = useCurrentOrganization()?.id

  return useMutation({
    mutationFn: ({ member, role }: { member: Member; role: OrganizationRole }) =>
      apiFetch<{ data: Member }>(`/api/members/${member.id}`, { method: 'PATCH', body: { role } }),
    onSuccess: async (_data, { member }) => {
      // Your own role changed: your permissions did too, so both lists' `can` flags
      // (and the roles you may invite with) are out of date.
      if (member.is_me) {
        await reloadUser()
        await queryClient.invalidateQueries({ queryKey: ['org', organizationId] })
        return
      }
      await queryClient.invalidateQueries({ queryKey: ['org', organizationId, 'members'] })
    },
  })
}

/**
 * Remove someone, or leave (when `member.is_me`). Leaving is handled here, not in
 * the caller's mutate() callbacks: dropping the old organization's queries
 * unmounts the members list, and TanStack Query skips the callbacks of a mutate()
 * whose component is gone; the ones given to useMutation always run.
 */
export function useRemoveMember() {
  const queryClient = useQueryClient()
  const organization = useCurrentOrganization()
  const navigate = useNavigate()

  return useMutation({
    mutationFn: (member: Member) => apiFetch<void>(`/api/members/${member.id}`, { method: 'DELETE' }),
    // Runs before the request; what it returns is the `context` of the callbacks below.
    onMutate: () => ({ organizationId: organization?.id, organizationName: organization?.name }),
    onSuccess: async (_data, member, context) => {
      if (!member.is_me) {
        await queryClient.invalidateQueries({ queryKey: ['org', context.organizationId, 'members'] })
        return
      }

      // The server moved us to another organization (or none): catch up, drop
      // everything that belonged to the one we left, and go home.
      await reloadUser()
      queryClient.removeQueries({ queryKey: ['org'] })
      void queryClient.invalidateQueries({ queryKey: organizationsQuery.queryKey })
      toast.success(`You left ${context.organizationName ?? 'the organization'}`)
      void navigate('/')
    },
  })
}

export function useInvite() {
  const queryClient = useQueryClient()
  const organizationId = useCurrentOrganization()?.id

  return useMutation({
    mutationFn: (body: { email: string; role: OrganizationRole }) =>
      apiFetch<{ data: Invitation }>('/api/invitations', { method: 'POST', body }),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['org', organizationId, 'invitations'] }),
  })
}

export function useRevokeInvitation() {
  const queryClient = useQueryClient()
  const organizationId = useCurrentOrganization()?.id

  return useMutation({
    mutationFn: (invitation: Invitation) => apiFetch<void>(`/api/invitations/${invitation.id}`, { method: 'DELETE' }),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['org', organizationId, 'invitations'] }),
  })
}
