import { beforeEach, describe, expect, it, vi } from 'vitest'
import { cookieJar, json, mockFetch, RedirectError } from '@/test/next'
import { completeTwoFactor, login, logout, register } from './actions'

vi.mock('next/headers', async () => (await import('@/test/next')).nextHeaders)
vi.mock('next/navigation', async () => (await import('@/test/next')).nextNavigation)

function form(values: Record<string, string>): FormData {
  const data = new FormData()
  for (const [key, value] of Object.entries(values)) {
    data.set(key, value)
  }
  return data
}

const user = { id: 1, name: 'Ada', email: 'ada@example.com' }

beforeEach(() => cookieJar.clear())

describe('login', () => {
  it('stores the token in the cookie and redirects to ?redirect=', async () => {
    const calls = mockFetch({ 'POST /api/login': () => json({ user, token: 'new-token' }) })

    await expect(login({}, form({ email: 'Ada@Example.com ', password: 'secret', redirect: '/tasks?status=done' }))).rejects.toEqual(
      new RedirectError('/tasks?status=done'),
    )

    expect(cookieJar.get('session')).toBe('new-token')
    expect(JSON.parse(calls[0].init.body as string)).toEqual({ email: 'ada@example.com', password: 'secret' })
    // The browser's User-Agent goes along, so the API names the device.
    expect(new Headers(calls[0].init.headers).get('user-agent')).toBe('Vitest Browser')
  })

  it('never redirects off the site', async () => {
    mockFetch({ 'POST /api/login': () => json({ user, token: 't' }) })

    await expect(login({}, form({ email: 'ada@example.com', password: 'secret', redirect: '//evil.com' }))).rejects.toEqual(
      new RedirectError('/'),
    )
  })

  it('puts the API 422 on the fields and keeps the email', async () => {
    mockFetch({
      'POST /api/login': () => json({ message: 'Invalid.', errors: { email: ['These credentials do not match our records.'] } }, 422),
    })

    const state = await login({}, form({ email: 'ada@example.com', password: 'wrong' }))

    expect(state).toEqual({ errors: { email: 'These credentials do not match our records.' }, values: { email: 'ada@example.com' } })
    expect(cookieJar.has('session')).toBe(false)
  })

  it('checks the fields before calling the API', async () => {
    const calls = mockFetch({})

    const state = await login({}, form({ email: '', password: '' }))

    expect(state.errors).toEqual({ email: 'Enter your email.', password: 'Enter your password.' })
    expect(calls).toHaveLength(0)
  })

  it('returns the challenge when two-factor is on, without storing anything', async () => {
    mockFetch({ 'POST /api/login': () => json({ two_factor: true, challenge: 'abc' }) })

    const state = await login({}, form({ email: 'ada@example.com', password: 'secret' }))

    expect(state).toEqual({ challenge: 'abc', values: { email: 'ada@example.com' } })
    expect(cookieJar.has('session')).toBe(false)
  })

  it('says when the API is down', async () => {
    mockFetch({ 'POST /api/login': () => Promise.reject(new TypeError('fetch failed')) })

    const state = await login({}, form({ email: 'ada@example.com', password: 'secret' }))

    expect(state.message).toBe('Cannot reach the server. Is the API running?')
  })
})

describe('the two-factor step', () => {
  it('logs in with a code', async () => {
    const calls = mockFetch({ 'POST /api/two-factor-challenge': () => json({ user, token: '2fa-token' }) })

    await expect(completeTwoFactor({}, form({ challenge: 'abc', code: '123456', redirect: '/tasks' }))).rejects.toEqual(
      new RedirectError('/tasks'),
    )

    expect(cookieJar.get('session')).toBe('2fa-token')
    expect(JSON.parse(calls[0].init.body as string)).toEqual({ challenge: 'abc', code: '123456' })
  })

  it('keeps the challenge after a wrong code', async () => {
    mockFetch({ 'POST /api/two-factor-challenge': () => json({ message: 'x', errors: { code: ['That code is not valid.'] } }, 422) })

    const state = await completeTwoFactor({}, form({ challenge: 'abc', code: '000000' }))

    expect(state).toMatchObject({ challenge: 'abc', errors: { code: 'That code is not valid.' } })
  })

  it('ends the login when the challenge expired', async () => {
    mockFetch({
      'POST /api/two-factor-challenge': () => json({ message: 'x', errors: { challenge: ['This login expired. Log in again.'] } }, 422),
    })

    const state = await completeTwoFactor({}, form({ challenge: 'abc', code: '123456' }))

    expect(state).toEqual({ message: 'This login expired. Log in again.' })
  })
})

describe('register', () => {
  it('stores the token and goes to the overview', async () => {
    mockFetch({ 'POST /api/register': () => json({ user, token: 'reg-token' }, 201) })

    await expect(
      register({}, form({ name: 'Ada', email: 'ada@example.com', password: 'password123', password_confirmation: 'password123' })),
    ).rejects.toEqual(new RedirectError('/'))

    expect(cookieJar.get('session')).toBe('reg-token')
  })
})

describe('logout', () => {
  it('clears the cookie even when the API call fails', async () => {
    cookieJar.set('session', 'revoked')
    mockFetch({ 'POST /api/logout': () => json({ message: 'Unauthenticated.' }, 401) })

    await expect(logout()).rejects.toEqual(new RedirectError('/login'))

    expect(cookieJar.has('session')).toBe(false)
  })
})
