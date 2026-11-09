import { type NextRequest, NextResponse } from 'next/server'
import { getCurrentUser } from '@/lib/dal'
import { safeRedirect } from '@/lib/safeRedirect'
import { SESSION_COOKIE } from '@/lib/session'

/**
 * Where logged-in pages send a request whose cookie the API no longer accepts:
 * pages can't delete cookies while rendering, a Route Handler can. It checks the
 * token first, so a link to it can't log anyone out.
 */
export async function GET(request: NextRequest) {
  const from = safeRedirect(request.nextUrl.searchParams.get('redirect'))
  if (await getCurrentUser()) {
    return NextResponse.redirect(new URL(from, request.url))
  }

  const login = new URL('/login', request.url)
  if (from !== '/') {
    login.searchParams.set('redirect', from)
  }
  const response = NextResponse.redirect(login)
  response.cookies.delete(SESSION_COOKIE)

  return response
}
