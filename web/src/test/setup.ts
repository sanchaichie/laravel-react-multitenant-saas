import '@testing-library/jest-dom/vitest'
import { cleanup } from '@testing-library/react'
import { afterEach, vi } from 'vitest'
import { queryClient } from '@/lib/queryClient'
import { useAuth } from '@/stores/auth'

// Every test starts logged out, with an empty cache and the real fetch.
afterEach(() => {
  cleanup()
  useAuth.setState({ token: null, user: null })
  localStorage.clear()
  queryClient.clear()
  vi.unstubAllGlobals()
  vi.restoreAllMocks()
})
