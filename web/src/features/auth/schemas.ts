import { z } from 'zod'

// Client-side checks that match the API's rules (api/app/Http/Requests/Auth), so
// most mistakes show before a request. The API still validates everything.

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
    message: 'The passwords don\'t match.',
    path: ['password_confirmation'],
  })

export type LoginValues = z.infer<typeof loginSchema>
export type RegisterValues = z.infer<typeof registerSchema>
