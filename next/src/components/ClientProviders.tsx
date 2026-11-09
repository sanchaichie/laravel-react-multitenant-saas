'use client'

import { useState } from 'react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { useClientApiEvents } from '@/hooks/use-client-api-events'
import { ApiError } from '@/lib/errors'

/**
 * Client-side state for the logged-in pages: one TanStack Query cache per browser
 * session (in useState, so a re-render never makes a new one; never module-level,
 * which on the server would be shared between users), and the API's 401/409 moves.
 * Page data still comes from Server Components; the cache is for client-only data
 * that refetches, like a chat's history.
 */
export function ClientProviders({ children }: { children: React.ReactNode }) {
  const [queryClient] = useState(
    () => new QueryClient({
        defaultOptions: {
          // Retry only a network failure: an API answer (401, 403, 404, 409, 422) won't change,
          // and retrying a 401 or 409 would repeat its navigation.
          queries: { retry: (count, error) => !(error instanceof ApiError) && count < 1, refetchOnWindowFocus: false },
        },
      }),
  )
  useClientApiEvents(queryClient)

  return <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>
}
