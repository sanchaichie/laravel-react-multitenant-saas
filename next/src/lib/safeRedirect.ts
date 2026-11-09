/**
 * The `?redirect=` target after login, limited to paths on this site (the same
 * rules as web/src/lib/safeRedirect.ts): absolute URLs, `//evil.com`, backslashes
 * and control characters fall back to `/`, so login can't be an open redirect.
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
