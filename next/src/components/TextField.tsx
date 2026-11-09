'use client'

import type { ComponentProps } from 'react'
import { Field, FieldDescription, FieldError, FieldLabel } from '@/components/ui/field'
import { Input } from '@/components/ui/input'

interface TextFieldProps extends Omit<ComponentProps<'input'>, 'id' | 'name'> {
  name: string
  label: string
  /** The server's message for this field (from the action's state). */
  error?: string
  hint?: string
  /** Puts a "Forgot password?"-style link on the label's line. */
  labelAside?: React.ReactNode
}

/**
 * A labelled input for a form posted to a Server Action: a plain, uncontrolled
 * <input name=...> (no react-hook-form), with the server's error under it and wired
 * to it for screen readers.
 */
export function TextField({ name, label, error, hint, labelAside, ...props }: TextFieldProps) {
  const id = `field-${name}`
  const describedBy = [hint && `${id}-hint`, error && `${id}-error`].filter(Boolean).join(' ') || undefined

  return (
    <Field data-invalid={Boolean(error)} controlId={id}>
      <div className="flex items-center justify-between gap-2">
        <FieldLabel>{label}</FieldLabel>
        {labelAside}
      </div>
      {hint && <FieldDescription id={`${id}-hint`}>{hint}</FieldDescription>}
      <Input name={name} aria-invalid={error ? true : undefined} aria-describedby={describedBy} {...props} />
      {error && <FieldError id={`${id}-error`}>{error}</FieldError>}
    </Field>
  )
}
