import '@testing-library/jest-dom/vitest'
import { cleanup } from '@testing-library/react'
import { afterEach, vi } from 'vitest'
import { queryClient } from '@/lib/queryClient'
import { useAuth } from '@/stores/auth'

// jsdom has no matchMedia (next-themes and the sidebar's useIsMobile use it):
// answer as a desktop screen in light mode.
Object.defineProperty(window, 'matchMedia', {
  writable: true,
  value: (query: string): MediaQueryList => ({
    matches: false,
    media: query,
    onchange: null,
    addEventListener: () => {},
    removeEventListener: () => {},
    addListener: () => {},
    removeListener: () => {},
    dispatchEvent: () => false,
  }),
})

// Browser APIs Radix (menus, dialogs, tooltips) calls that jsdom doesn't have.
class ResizeObserverStub {
  observe() {}
  unobserve() {}
  disconnect() {}
}
globalThis.ResizeObserver ??= ResizeObserverStub
Element.prototype.hasPointerCapture ??= () => false
Element.prototype.releasePointerCapture ??= () => {}
Element.prototype.scrollIntoView ??= () => {}

// Every test starts logged out, with an empty cache, the real fetch, the default
// theme and an expanded sidebar.
afterEach(() => {
  cleanup()
  useAuth.setState({ token: null, user: null, chooseOrganization: false })
  localStorage.clear()
  document.documentElement.className = ''
  document.cookie = 'sidebar_state=; max-age=0; path=/'
  queryClient.clear()
  vi.unstubAllGlobals()
  vi.restoreAllMocks()
})
