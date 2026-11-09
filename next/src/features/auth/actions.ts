'use server'

// Server Actions: functions the forms call directly. They run on the server, so
// the API token never reaches the browser: the login answer's token goes straight
// into the httpOnly cookie. (web/ does the same with useMutation + apiFetch in the
// browser, and keeps the token in localStorage.) Next.js checks each call's Origin.

import { redirect } from 'next/navigation'
import type { AuthResponse, LoginResult, User } from '@shared/types/api'
import { serverApi } from '@/lib/api'
import { ApiError, errorMessage, fieldErrors } from '@/lib/errors'
import { safeRedirect } from '@/lib/safeRedirect'
import { clearToken, setToken } from '@/lib/session'
import {
  forgotPasswordSchema,
  issuesByField,
  loginSchema,
  registerSchema,
  resetPasswordSchema,
  twoFactorSchema,
} from './schemas'
import { type AuthFormState, type FormState, text } from './state'

/** An API error as form state: messages on the fields that have one, else one for the form. */
function failure(error: unknown, fields: readonly string[], values: Record<string, string>): FormState {
  const errors = fieldErrors(error, fields)

  return Object.keys(errors).length > 0 ? { errors, values } : { message: errorMessage(error), values }
}

export async function login(_previous: AuthFormState, formData: FormData): Promise<AuthFormState> {
  const values = { email: text(formData, 'email').trim().toLowerCase() }
  const parsed = loginSchema.safeParse({ ...values, password: text(formData, 'password') })
  if (!parsed.success) {
    return { errors: issuesByField(parsed.error), values }
  }

  let result: LoginResult
  try {
    result = await serverApi<LoginResult>('/api/login', { method: 'POST', body: parsed.data, token: null })
  } catch (error) {
    return failure(error, ['email', 'password'], values)
  }

  // `'challenge' in result` narrows the union, as in web/: two-factor is on, ask for a code.
  if ('challenge' in result) {
    return { challenge: result.challenge, values }
  }

  await setToken(result.token)
  // redirect() throws to end the action; Next.js then renders the target page.
  redirect(safeRedirect(text(formData, 'redirect')))
}

export async function completeTwoFactor(_previous: AuthFormState, formData: FormData): Promise<AuthFormState> {
  const challenge = text(formData, 'challenge')
  const recovery = formData.has('recovery_code')
  const parsed = twoFactorSchema.safeParse(
    recovery ? { challenge, recovery_code: text(formData, 'recovery_code') } : { challenge, code: text(formData, 'code') },
  )
  if (!parsed.success) {
    return { challenge, attempt: Date.now(), errors: issuesByField(parsed.error) }
  }

  let result: AuthResponse
  try {
    result = await serverApi<AuthResponse>('/api/two-factor-challenge', { method: 'POST', body: parsed.data, token: null })
  } catch (error) {
    // The challenge itself is gone (5 minutes, 5 wrong tries): this login is over.
    const expired = error instanceof ApiError && error.status === 422 ? error.data?.errors?.challenge?.[0] : undefined
    if (expired) {
      return { message: expired }
    }
    return { challenge, attempt: Date.now(), ...failure(error, ['code', 'recovery_code'], {}) }
  }

  await setToken(result.token)
  redirect(safeRedirect(text(formData, 'redirect')))
}

export async function register(_previous: FormState, formData: FormData): Promise<FormState> {
  const values = { name: text(formData, 'name'), email: text(formData, 'email').trim().toLowerCase() }
  const parsed = registerSchema.safeParse({
    ...values,
    password: text(formData, 'password'),
    password_confirmation: text(formData, 'password_confirmation'),
  })
  if (!parsed.success) {
    return { errors: issuesByField(parsed.error), values }
  }

  let result: AuthResponse
  try {
    result = await serverApi<AuthResponse>('/api/register', { method: 'POST', body: parsed.data, token: null })
  } catch (error) {
    return failure(error, ['name', 'email', 'password', 'password_confirmation'], values)
  }

  await setToken(result.token)
  redirect('/')
}

/** Always the same answer, whether an account uses the address or not. */
export async function forgotPassword(_previous: FormState, formData: FormData): Promise<FormState> {
  const values = { email: text(formData, 'email').trim().toLowerCase() }
  const parsed = forgotPasswordSchema.safeParse(values)
  if (!parsed.success) {
    return { errors: issuesByField(parsed.error), values }
  }

  try {
    const { message } = await serverApi<{ message: string }>('/api/forgot-password', {
      method: 'POST',
      body: parsed.data,
      token: null,
    })
    return { done: true, message, values }
  } catch (error) {
    return failure(error, ['email'], values)
  }
}

export async function resetPassword(_previous: AuthFormState, formData: FormData): Promise<AuthFormState> {
  const parsed = resetPasswordSchema.safeParse({
    token: text(formData, 'token'),
    email: text(formData, 'email'),
    password: text(formData, 'password'),
    password_confirmation: text(formData, 'password_confirmation'),
  })
  if (!parsed.success) {
    return { errors: issuesByField(parsed.error) }
  }

  let result: LoginResult
  try {
    result = await serverApi<LoginResult>('/api/reset-password', { method: 'POST', body: parsed.data, token: null })
  } catch (error) {
    // The link's own problems (expired, used) come back on `email`: say them for the form.
    return failure(error, ['password', 'password_confirmation'], {})
  }

  if ('challenge' in result) {
    return { challenge: result.challenge }
  }

  await setToken(result.token)
  redirect('/')
}

export async function logout(): Promise<void> {
  // Log out here even if the API call failed (say, the token was already revoked).
  await serverApi('/api/logout', { method: 'POST' }).catch(() => undefined)
  await clearToken()
  redirect('/login')
}

/** The emailed link's four parts, posted back to the API (it checks the signature and expiry). */
export async function verifyEmail(link: { id: string; hash: string; expires: string; signature: string }): Promise<
  { ok: true; user: User } | { ok: false; message: string }
> {
  const query = new URLSearchParams({ expires: link.expires, signature: link.signature })
  try {
    const { data } = await serverApi<{ data: User }>(
      `/api/email/verify/${encodeURIComponent(link.id)}/${encodeURIComponent(link.hash)}?${query}`,
      { method: 'POST' },
    )
    return { ok: true, user: data }
  } catch (error) {
    return { ok: false, message: errorMessage(error) }
  }
}

export async function resendVerification(): Promise<{ ok: boolean; message: string }> {
  try {
    const { message } = await serverApi<{ message: string }>('/api/email/verification-notification', { method: 'POST' })
    return { ok: true, message }
  } catch (error) {
    return { ok: false, message: errorMessage(error) }
  }
}
