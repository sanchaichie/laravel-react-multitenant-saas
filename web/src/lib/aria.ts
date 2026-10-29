/**
 * An aria-describedby value from the ids that apply right now, e.g.
 * describedBy(!errors.password && 'hint-id', errors.password && 'error-id').
 * Screen readers then read a field's hint or error with it.
 */
export function describedBy(...ids: Array<string | false | null | undefined>): string | undefined {
  const present = ids.filter((id): id is string => typeof id === 'string' && id !== '')

  return present.length > 0 ? present.join(' ') : undefined
}
