import 'server-only'

/** The Laravel API, read on the server only: the browser talks to this app's /api proxy instead. */
export function apiBaseUrl(): string {
  return (process.env.API_BASE_URL || 'http://localhost:8100').replace(/\/$/, '')
}
