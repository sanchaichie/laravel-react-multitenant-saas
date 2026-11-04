// A fixed locale and time zone, so a date reads the same for everyone (and in tests).
const dateFormatter = new Intl.DateTimeFormat('en-US', { dateStyle: 'medium', timeZone: 'UTC' })

/** An API timestamp (ISO 8601) as a date, e.g. "Oct 29, 2026". */
export function formatDate(iso: string): string {
  return dateFormatter.format(new Date(iso))
}

const dateTimeFormatter = new Intl.DateTimeFormat('en-US', { dateStyle: 'medium', timeStyle: 'short', timeZone: 'UTC' })

/** An API timestamp as a date and time in UTC, e.g. "Oct 29, 2026, 2:05 PM UTC". */
export function formatDateTime(iso: string): string {
  return `${dateTimeFormatter.format(new Date(iso))} UTC`
}

const usdFormatter = new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' })
const smallUsdFormatter = new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD', maximumFractionDigits: 4 })

/** USD with cents, e.g. "$1,234.50"; amounts under a cent keep 4 decimals ("$0.0035"). */
export function formatUsd(amount: number): string {
  return amount > 0 && amount < 0.01 ? smallUsdFormatter.format(amount) : usdFormatter.format(amount)
}

const monthFormatter = new Intl.DateTimeFormat('en-US', { month: 'long', year: 'numeric', timeZone: 'UTC' })

/** A YYYY-MM month, e.g. "October 2026". */
export function formatMonth(month: string): string {
  return monthFormatter.format(new Date(`${month}-01T00:00:00Z`))
}

const dayFormatter = new Intl.DateTimeFormat('en-US', { dateStyle: 'medium', timeZone: 'UTC' })

/** A YYYY-MM-DD day, e.g. "Oct 29, 2026". */
export function formatDay(day: string): string {
  return dayFormatter.format(new Date(`${day}T00:00:00Z`))
}

/**
 * The last day before an exclusive end (ISO 8601), e.g. "2026-12-01T00:00:00Z" →
 * "Nov 30, 2026": what "until" means to a reader.
 */
export function formatLastDay(exclusiveEnd: string): string {
  return dateFormatter.format(new Date(Date.parse(exclusiveEnd) - 1))
}
