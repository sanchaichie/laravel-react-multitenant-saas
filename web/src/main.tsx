import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
// The DOM build: it can apply a navigation with ReactDOM.flushSync (see useLogout).
import { RouterProvider } from 'react-router/dom'
import { AppProviders } from '@/components/AppProviders'
import { FullPageSpinner } from '@/components/FullPageSpinner'
import { bootstrapSession } from '@/features/auth/session'
import { ApiError } from '@/lib/api'
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
    <AppProviders>
      <RouterProvider router={router} />
    </AppProviders>
  </StrictMode>,
)
