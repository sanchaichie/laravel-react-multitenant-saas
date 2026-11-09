import 'server-only'

/**
 * The browser's IP address, to pass to Laravel as X-Forwarded-For (it trusts that
 * header only from this server: TRUSTED_PROXIES in api/.env). Read from the header
 * the reverse proxy in front of this app sets, named by CLIENT_IP_HEADER (e.g.
 * `x-real-ip`, or `x-forwarded-for`, whose last entry is the one that proxy added).
 *
 * Unset: null, and nothing is sent. Next.js itself only fills x-forwarded-for when
 * the client didn't send one, so without a proxy in front its value is whatever
 * the client claims; trusting it would let anyone pick their own rate-limit bucket.
 */
export function clientIp(headers: Headers): string | null {
  const name = process.env.CLIENT_IP_HEADER?.trim().toLowerCase()
  if (!name) {
    return null
  }
  const ip = headers.get(name)?.split(',').at(-1)?.trim()

  // An IPv4 or IPv6 address, nothing else (no header injection, no hostnames).
  return ip && /^[0-9a-fA-F:.]{2,45}$/.test(ip) ? ip : null
}
