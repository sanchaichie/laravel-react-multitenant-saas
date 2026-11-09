import 'server-only'
import { cookies } from 'next/headers'

/**
 * The API token lives in an httpOnly cookie: page scripts can't read it, so an
 * XSS bug can't steal it (web/ keeps it in localStorage). Only server code (pages,
 * Server Actions, the /api proxy) reads it and sends it to Laravel.
 */
export const SESSION_COOKIE = 'session'

// Sanctum tokens don't expire on their own; the cookie lasts as long as a "remember me".
const MAX_AGE = 60 * 60 * 24 * 30

export async function getToken(): Promise<string | null> {
  return (await cookies()).get(SESSION_COOKIE)?.value ?? null
}

/** Only in a Server Action or a Route Handler: a page can't set cookies while rendering. */
export async function setToken(token: string): Promise<void> {
  ;(await cookies()).set(SESSION_COOKIE, token, sessionCookieOptions())
}

export async function clearToken(): Promise<void> {
  ;(await cookies()).delete(SESSION_COOKIE)
}

export function sessionCookieOptions() {
  return {
    httpOnly: true,
    // Lax: not sent on cross-site POSTs (CSRF), still sent when following a link here.
    sameSite: 'lax' as const,
    // Browsers accept Secure cookies on http://localhost, so the e2e build keeps it too.
    secure: process.env.NODE_ENV === 'production',
    path: '/',
    maxAge: MAX_AGE,
  }
}
