import { queryOptions, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useNavigate } from 'react-router'
import { toast } from 'sonner'
import { apiFetch } from '@/lib/api'
import { useAuth } from '@/stores/auth'
import type { Device, User } from '@/types/api'

// The user's own account: user-level keys (not ['org', ...]), untouched by switching.

export const devicesQuery = queryOptions({
  queryKey: ['account', 'devices'],
  queryFn: ({ signal }) => apiFetch<{ data: Device[] }>('/api/account/devices', { signal }),
})

export function useDevices() {
  return useQuery(devicesQuery)
}

/** Name and email changes answer with the user: store it, so the sidebar and banner update. */
function useStoreUser() {
  const setUser = useAuth((s) => s.setUser)
  return ({ data }: { data: User }) => setUser(data)
}

export function useUpdateProfile() {
  const storeUser = useStoreUser()
  return useMutation({
    mutationFn: (body: { name: string }) => apiFetch<{ data: User }>('/api/account', { method: 'PATCH', body }),
    // The toast is here, not in mutate(): storing the new name remounts the card
    // (keyed by the name), and TanStack Query skips a gone component's callbacks.
    onSuccess: (data) => {
      storeUser(data)
      toast.success('Name saved')
    },
  })
}

/** Asks for the change: the email stays until the new address confirms it. */
export function useChangeEmail() {
  const storeUser = useStoreUser()
  return useMutation({
    mutationFn: (body: { email: string; current_password: string }) =>
      apiFetch<{ data: User }>('/api/account/email', { method: 'PUT', body: { ...body, email: body.email.toLowerCase() } }),
    onSuccess: storeUser,
  })
}

export function useCancelEmailChange() {
  const storeUser = useStoreUser()
  return useMutation({
    mutationFn: () => apiFetch<{ data: User }>('/api/account/email/pending', { method: 'DELETE' }),
    onSuccess: storeUser,
  })
}

/** The four parts of the link in the confirmation email, as /confirm-email receives them. */
export interface EmailChangeLink {
  id: string
  hash: string
  expires: string
  signature: string
}

/** Post the emailed link back: the new address becomes the account's, and other devices are signed out. */
export function useConfirmEmailChange() {
  const storeUser = useStoreUser()
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: ({ id, hash, expires, signature }: EmailChangeLink) =>
      apiFetch<{ data: User }>(
        `/api/account/email/confirm/${encodeURIComponent(id)}/${encodeURIComponent(hash)}?${new URLSearchParams({ expires, signature })}`,
        { method: 'POST' },
      ),
    onSuccess: (data) => {
      storeUser(data)
      return queryClient.invalidateQueries({ queryKey: devicesQuery.queryKey })
    },
  })
}

export function useChangePassword() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (body: { current_password: string; password: string; password_confirmation: string }) =>
      apiFetch<void>('/api/account/password', { method: 'PUT', body }),
    // Other devices were signed out.
    onSuccess: () => queryClient.invalidateQueries({ queryKey: devicesQuery.queryKey }),
  })
}

/**
 * Logged out here, then to /login in the same render (see useLogout: flushSync, so
 * RequireAuth doesn't first send us to /login?redirect=… as if the session expired).
 */
function useEndSession() {
  const clearSession = useAuth((s) => s.clearSession)
  const navigate = useNavigate()
  return () => {
    clearSession()
    void navigate('/login', { replace: true, flushSync: true })
  }
}

export function useRevokeDevice() {
  const queryClient = useQueryClient()
  const endSession = useEndSession()
  return useMutation({
    mutationFn: (device: Device) => apiFetch<void>(`/api/account/devices/${device.id}`, { method: 'DELETE' }),
    // In useMutation, not mutate(): it must run even if the list re-rendered meanwhile.
    onSuccess: (_data, device) => {
      if (device.is_current) {
        endSession()
        return
      }
      queryClient.setQueryData(devicesQuery.queryKey, (old) =>
        old ? { data: old.data.filter((d) => d.id !== device.id) } : old,
      )
    },
  })
}

export function useDeleteAccount() {
  const endSession = useEndSession()
  return useMutation({
    mutationFn: (password: string) => apiFetch<void>('/api/account', { method: 'DELETE', body: { password } }),
    // Here, before leaving: the page (and mutate()'s callbacks) are gone after.
    onSuccess: () => {
      toast.success('Your account was deleted')
      endSession()
    },
  })
}
