/** An id from the URL (up to 18 digits, like the API's routes), or null when it isn't one. */
export function parseId(value: string | undefined): number | null {
  return value && /^[1-9]\d{0,17}$/.test(value) ? Number(value) : null
}
