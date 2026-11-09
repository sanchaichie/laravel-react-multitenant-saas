import { type NextRequest, NextResponse } from 'next/server'

// Next 16's proxy.ts (was middleware.ts) ↔ web's RequireAuth component: it runs
// before a page renders. Here it only checks that a session cookie exists; the API
// decides whether it's still good (pages call /api/me and go to /session-expired
// on a 401). It also tells pages their own URL (x-url), for ?redirect= after login.

const GUEST_PAGES = ['/login', '/register', '/forgot-password', '/reset-password']
// Email links whose page isn't in the Next app yet: they say so, logged in or not.
const NOT_PORTED_PAGES = ['/join', '/confirm-email', '/revert-email', '/revert-two-factor']

export function proxy(request: NextRequest) {
  const { pathname, search } = request.nextUrl
  const isPublic = [...GUEST_PAGES, ...NOT_PORTED_PAGES, '/session-expired'].includes(pathname)

  if (!isPublic && !request.cookies.has('session')) {
    const login = new URL('/login', request.url)
    if (pathname !== '/') {
      login.searchParams.set('redirect', pathname + search)
    }
    return NextResponse.redirect(login)
  }

  const headers = new Headers(request.headers)
  headers.set('x-url', pathname + search)

  return NextResponse.next({ request: { headers } })
}

export const config = {
  // Pages only: not the /api proxy (it answers 401 itself), Next's assets or public files.
  matcher: ['/((?!api/|_next/|images/|favicon.ico).*)'],
}
