import { type NextRequest, NextResponse } from 'next/server'
import { clientIp } from '@/lib/clientIp'
import { apiBaseUrl } from '@/lib/config'
import { SESSION_COOKIE } from '@/lib/session'

/**
 * The backend-for-frontend proxy: client components call /api/... on this origin,
 * and this Route Handler forwards the call to Laravel with the token from the
 * httpOnly cookie. So the browser never holds the token, and the API needs no CORS
 * for this app. The body streams through, which server-sent events need.
 */

// Only the API areas this app's client code uses. An allowlist, not a denylist:
// routes that hand out a token (login, two-factor, an admin's "view as user") must
// stay with the Server Actions or out of reach, so a token never reaches page scripts.
const ALLOWED = [/^me$/, /^organizations(\/\d+)?$/, /^tasks(\/.*)?$/, /^members$/, /^email\/verification-notification$/]

// A request body is read whole before it's sent on: cap it (Laravel caps it too, but only after Next has read it).
const MAX_BODY_BYTES = 1024 * 1024
const BODY_DEADLINE_MS = 10_000

// Request headers passed on (everything else, cookies included, stays here).
const FORWARDED_REQUEST_HEADERS = ['accept', 'content-type', 'x-organization-id', 'user-agent']
// Response headers passed back.
const FORWARDED_RESPONSE_HEADERS = ['content-type', 'cache-control', 'retry-after', 'x-accel-buffering']

async function forward(request: NextRequest, { params }: RouteContext<'/api/[...path]'>): Promise<Response> {
  const { path } = await params

  // Dot segments would be resolved by the URL parser (/api/./login → /api/login),
  // slipping past the allowlist: refuse them, and empty ones, outright.
  const joined = path.join('/')
  if (path.some((segment) => segment === '' || segment === '.' || segment === '..') || !ALLOWED.some((route) => route.test(joined))) {
    return NextResponse.json({ message: 'Not found.' }, { status: 404 })
  }

  const hasBody = request.method !== 'GET' && request.method !== 'HEAD'
  if (hasBody) {
    // CSRF: the cookie is SameSite=Lax (not sent on cross-site POSTs), and a write
    // must come from a page of this site. Compared by host, which a TLS-terminating
    // proxy in front keeps (it changes the scheme Next sees).
    const origin = request.headers.get('origin')
    const host = request.headers.get('x-forwarded-host') ?? request.headers.get('host') ?? request.nextUrl.host
    if (origin !== null && (origin === 'null' || new URL(origin).host !== host)) {
      return NextResponse.json({ message: 'Cross-site request refused.' }, { status: 403 })
    }

    const length = request.headers.get('content-length')
    if (length !== null && Number(length) > MAX_BODY_BYTES) {
      return NextResponse.json({ message: 'The request is too large.' }, { status: 413 })
    }
  }

  const headers = new Headers()
  for (const name of FORWARDED_REQUEST_HEADERS) {
    const value = request.headers.get(name)
    if (value !== null) {
      headers.set(name, value)
    }
  }
  headers.set('accept', headers.get('accept') ?? 'application/json')
  // The browser's IP from the trusted proxy's header (never a client's own X-Forwarded-For).
  const ip = clientIp(request.headers)
  if (ip) {
    headers.set('x-forwarded-for', ip)
  }
  const token = request.cookies.get(SESSION_COOKIE)?.value
  if (token) {
    headers.set('authorization', `Bearer ${token}`)
  }

  const target = `${apiBaseUrl()}/api/${path.map(encodeURIComponent).join('/')}${request.nextUrl.search}`
  let body: ArrayBuffer | undefined
  if (hasBody) {
    const read = await readCapped(request)
    if (read === null) {
      return NextResponse.json({ message: 'The request is too large.' }, { status: 413 })
    }
    body = read
  }

  let upstream: Response
  try {
    upstream = await fetch(target, {
      method: request.method,
      headers,
      body,
      cache: 'no-store',
      redirect: 'manual',
      // Stop the call to Laravel when the browser goes away.
      signal: request.signal,
    })
  } catch {
    return NextResponse.json({ message: 'Cannot reach the server.' }, { status: 502 })
  }

  const responseHeaders = new Headers()
  for (const name of FORWARDED_RESPONSE_HEADERS) {
    const value = upstream.headers.get(name)
    if (value !== null) {
      responseHeaders.set(name, value)
    }
  }

  // An event stream (AI chat, writing help) must reach the browser chunk by chunk:
  // `no-transform` keeps next start's gzip from holding small chunks back, and
  // X-Accel-Buffering (from Laravel) does the same for nginx in front.
  if (responseHeaders.get('content-type')?.startsWith('text/event-stream')) {
    responseHeaders.set('cache-control', 'private, no-store, no-transform')
  }

  const response = new NextResponse(upstream.body, { status: upstream.status, headers: responseHeaders })
  // The token is gone on the server (logged out elsewhere, revoked): drop the cookie too.
  if (upstream.status === 401 && token) {
    response.cookies.delete(SESSION_COOKIE)
  }

  return response
}

/** The body, or null past MAX_BODY_BYTES (counted as it streams in, whatever Content-Length said) or BODY_DEADLINE_MS. */
async function readCapped(request: NextRequest): Promise<ArrayBuffer | undefined | null> {
  if (!request.body) {
    return undefined
  }
  const chunks: Uint8Array[] = []
  let size = 0
  const reader = request.body.getReader()
  // A body sent very slowly would hold this request open: give up after 10s.
  const deadline = Date.now() + BODY_DEADLINE_MS
  for (;;) {
    const remaining = deadline - Date.now()
    const next = await Promise.race([
      reader.read(),
      new Promise<'timeout'>((resolve) => setTimeout(() => resolve('timeout'), Math.max(remaining, 0))),
    ])
    if (next === 'timeout') {
      await reader.cancel()
      return null
    }
    const { done, value } = next
    if (done) {
      break
    }
    size += value.byteLength
    if (size > MAX_BODY_BYTES) {
      await reader.cancel()
      return null
    }
    chunks.push(value)
  }
  const result = new Uint8Array(size)
  let offset = 0
  for (const chunk of chunks) {
    result.set(chunk, offset)
    offset += chunk.byteLength
  }
  return result.buffer
}

export { forward as GET, forward as POST, forward as PUT, forward as PATCH, forward as DELETE }
