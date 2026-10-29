import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import { QueryClientProvider } from '@tanstack/react-query'
// The DOM build: it can apply a navigation with ReactDOM.flushSync (see useLogout).
import { RouterProvider } from 'react-router/dom'
import { FullPageSpinner } from '@/components/FullPageSpinner'
import { Toaster } from '@/components/ui/sonner'
import { bootstrapSession } from '@/features/auth/session'
import { ApiError } from '@/lib/api'
import { queryClient } from '@/lib/queryClient'
import { router } from '@/router'
import './index.css'

const root = createRoot(document.getElementById('root')!)

// Load the saved session's user before the first real render, so no page is
// shown as logged in with an unknown user (or flashes the login page).
root.render(<FullPageSpinner />)

try {
  await bootstrapSession()
} catch (error) {
  // A 401 is just an expired token (apiFetch cleared it; RequireAuth asks to log
  // in). Anything else: RequireAuth shows the error and a Retry button.
  if (!(error instanceof ApiError && error.status === 401)) {
    console.error('Could not load the current user', error)
  }
}

// StrictMode renders every component twice in development, to catch side
// effects in the render body (they belong in event handlers or effects).
root.render(
  <StrictMode>
    <QueryClientProvider client={queryClient}>
      <RouterProvider router={router} />
      <Toaster theme="light" richColors closeButton />
    </QueryClientProvider>
  </StrictMode>,
)
