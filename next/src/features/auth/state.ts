/**
 * What a Server Action hands back to its form through useActionState: field
 * errors, a message for the whole form, and the values to put back (React resets
 * a form after its action runs, so typed text would otherwise be lost).
 */
export interface FormState {
  errors?: Record<string, string>
  message?: string
  values?: Record<string, string>
  /** Set by actions that end without leaving the page (a reset link sent). */
  done?: boolean
}

/** Login and password reset: with two-factor on, the next step's challenge. */
export interface AuthFormState extends FormState {
  challenge?: string
  /** Changes on every failed code, so the code boxes remount empty (the message may repeat). */
  attempt?: number
}

export const initialState: FormState = {}

/** A form field as a string ('' when missing or a file). */
export function text(formData: FormData, name: string): string {
  const value = formData.get(name)
  return typeof value === 'string' ? value : ''
}
