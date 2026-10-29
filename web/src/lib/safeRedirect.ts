/**
 * The `?redirect=` target after login, limited to paths on this site.
 * Anything else (absolute URLs, `//evil.com`, `/\evil.com`) falls back to `/`,
 * so the login page can't be used as an open redirect. So does any backslash or
 * control character: URL parsing drops tabs and newlines, so `/\t/evil.com`
 * would become `//evil.com`.
 */
export function safeRedirect(value: unknown): string {
  if (typeof value !== 'string' || !value.startsWith('/') || value.startsWith('//')) {
    return '/'
  }
  // eslint-disable-next-line no-control-regex -- matching control characters is the point
  if (/[\u0000-\u001F\u007F\\]/.test(value)) {
    return '/'
  }

  return value
}
