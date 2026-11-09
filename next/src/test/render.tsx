import type { ReactElement } from 'react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render } from '@testing-library/react'
import { Toaster } from 'sonner'

/** Renders a client component with a fresh query cache (no retries) and the toaster. */
export function renderWithClient(ui: ReactElement) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } })
  return {
    queryClient,
    ...render(
      <QueryClientProvider client={queryClient}>
        {ui}
        <Toaster />
      </QueryClientProvider>,
    ),
  }
}
