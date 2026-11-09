import { z } from 'zod'

// The same checks as web/src/features/auth/schemas.ts, run in the Server Action
// before calling the API (which still validates everything).

const email = z.email({
  error: (issue) => (issue.input === '' ? 'Enter your email.' : 'Enter a valid email address.'),
})

export const loginSchema = z.object({
  email,
  password: z.string().min(1, 'Enter your password.'),
})

export const registerSchema = z
  .object({
    name: z.string().trim().min(1, 'Enter your name.').max(255, 'At most 255 characters.'),
    email: email.max(255, 'At most 255 characters.'),
    password: z.string().min(8, 'At least 8 characters.'),
    password_confirmation: z.string().min(1, 'Repeat your password.'),
  })
  .refine((values) => values.password === values.password_confirmation, {
    message: "The passwords don't match.",
    path: ['password_confirmation'],
  })

export const forgotPasswordSchema = z.object({ email: email.max(255, 'At most 255 characters.') })

export const resetPasswordSchema = z
  .object({
    token: z.string().min(1),
    email,
    password: z.string().min(8, 'At least 8 characters.'),
    password_confirmation: z.string().min(1, 'Repeat your password.'),
  })
  .refine((values) => values.password === values.password_confirmation, {
    message: "The passwords don't match.",
    path: ['password_confirmation'],
  })

export const twoFactorSchema = z.union([
  z.object({ challenge: z.string().min(1), code: z.string().regex(/^\d{6}$/, 'Enter the 6 digits from the app.') }),
  z.object({ challenge: z.string().min(1), recovery_code: z.string().trim().min(1, 'Enter one of your recovery codes.') }),
])

/** zod's issues as one message per field (the first), the shape forms show. */
export function issuesByField(error: z.ZodError): Record<string, string> {
  const result: Record<string, string> = {}
  for (const issue of error.issues) {
    const field = String(issue.path[0] ?? '')
    result[field] ??= issue.message
  }

  return result
}
