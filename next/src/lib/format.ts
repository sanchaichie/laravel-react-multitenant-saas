// A fixed locale and time zone, so a date reads the same for everyone, on the
// server and in the browser (no hydration mismatch).
const dateFormatter = new Intl.DateTimeFormat('en-US', { dateStyle: 'medium', timeZone: 'UTC' })

/** An API timestamp (ISO 8601) or a YYYY-MM-DD day, e.g. "Oct 29, 2026". */
export function formatDate(value: string): string {
  return dateFormatter.format(new Date(/^\d{4}-\d{2}-\d{2}$/.test(value) ? `${value}T00:00:00Z` : value))
}
