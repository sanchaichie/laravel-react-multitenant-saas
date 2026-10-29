import { QueryClient } from '@tanstack/react-query'

// One client for the app: the auth store clears it when the session ends, so no
// cached data survives into the next user's session.
export const queryClient = new QueryClient({
  defaultOptions: {
    queries: { staleTime: 30_000, retry: 1, refetchOnWindowFocus: false },
  },
})
