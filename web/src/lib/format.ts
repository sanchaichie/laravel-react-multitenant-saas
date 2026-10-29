// A fixed locale and time zone, so a date reads the same for everyone (and in tests).
const dateFormatter = new Intl.DateTimeFormat('en-US', { dateStyle: 'medium', timeZone: 'UTC' })

/** An API timestamp (ISO 8601) as a date, e.g. "Oct 29, 2026". */
export function formatDate(iso: string): string {
  return dateFormatter.format(new Date(iso))
}
