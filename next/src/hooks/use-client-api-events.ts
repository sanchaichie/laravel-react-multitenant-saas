'use client'

import { useEffect } from 'react'
import type { QueryClient } from '@tanstack/react-query'
import { useRouter } from 'next/navigation'
import { toast } from 'sonner'
import { clientApiEvents } from '@/lib/clientApi'

/**
 * Wires clientApi's 401 and 409 to the page (once, in the app layout): a session
 * the API no longer accepts goes through /session-expired; another tab's switch
 * says so, goes to /tasks and re-renders the server parts for the new organization.
 */
export function useClientApiEvents(queryClient: QueryClient) {
  const router = useRouter()

  useEffect(() => {
    // A full page load: /session-expired is a Route Handler (not a page), and a session
    // that ended should drop everything held in memory, the query cache included.
    clientApiEvents.onUnauthenticated = () =>
      window.location.assign(`/session-expired?redirect=${encodeURIComponent(window.location.pathname + window.location.search)}`)
    clientApiEvents.onOrganizationChanged = () => {
      // Org-scoped client data belongs to the organization this tab thought was current.
      queryClient.removeQueries({ queryKey: ['org'] })
      toast.info('Your organization changed', { description: 'It was switched in another tab.' })
      router.replace('/tasks')
      router.refresh()
    }
  }, [router, queryClient])
}
