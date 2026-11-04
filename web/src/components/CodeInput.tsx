import type { Ref } from 'react'
import { REGEXP_ONLY_DIGITS } from 'input-otp'
import { InputOTP, InputOTPGroup, InputOTPSeparator, InputOTPSlot } from '@/components/ui/input-otp'

interface CodeInputProps {
  /** The real input underneath, so react-hook-form can focus it on an error (a Controller's field.ref). */
  ref?: Ref<HTMLInputElement>
  id: string
  value: string
  onChange: (value: string) => void
  /** Called once all 6 digits are in, e.g. to submit the form. */
  onComplete?: (value: string) => void
  onBlur?: () => void
  invalid?: boolean
  'aria-describedby'?: string
  autoFocus?: boolean
  disabled?: boolean
}

/**
 * The 6 digits from an authenticator app, as six boxes. A single real input sits
 * underneath (input-otp), so typing, pasting, autofill from a password manager
 * (autocomplete="one-time-code") and screen readers work as with any text field.
 * Controlled: react-hook-form drives it through a Controller, like a Select.
 */
export function CodeInput({ ref, id, value, onChange, onComplete, onBlur, invalid, autoFocus, disabled, ...aria }: CodeInputProps) {
  return (
    // React 19: `ref` is a plain prop, no forwardRef needed.
    <InputOTP
      ref={ref}
      id={id}
      maxLength={6}
      pattern={REGEXP_ONLY_DIGITS}
      inputMode="numeric"
      autoComplete="one-time-code"
      value={value}
      onChange={onChange}
      onComplete={onComplete}
      onBlur={onBlur}
      autoFocus={autoFocus}
      disabled={disabled}
      aria-invalid={invalid}
      aria-describedby={aria['aria-describedby']}
    >
      <InputOTPGroup>
        {[0, 1, 2].map((index) => (
          <InputOTPSlot key={index} index={index} aria-invalid={invalid} className="size-10 text-base" />
        ))}
      </InputOTPGroup>
      <InputOTPSeparator />
      <InputOTPGroup>
        {[3, 4, 5].map((index) => (
          <InputOTPSlot key={index} index={index} aria-invalid={invalid} className="size-10 text-base" />
        ))}
      </InputOTPGroup>
    </InputOTP>
  )
}
