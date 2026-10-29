import { describe, expect, it, vi } from 'vitest'
import { ApiError, NetworkError } from './api'
import { applyFieldErrors, errorMessage } from './apiErrors'

describe('applyFieldErrors', () => {
  it('puts the first message of each known field on the form, focusing the first', () => {
    const setError = vi.fn()
    const error = new ApiError(422, {
      message: 'Invalid.',
      errors: { email: ['Taken.', 'Second message.'], password: ['Too short.'], device_name: ['Not shown.'] },
    })

    expect(applyFieldErrors(error, setError, ['email', 'password'])).toBe(true)

    expect(setError.mock.calls).toEqual([
      ['email', { type: 'server', message: 'Taken.' }, { shouldFocus: true }],
      ['password', { type: 'server', message: 'Too short.' }, { shouldFocus: false }],
    ])
  })

  it.each([
    ['a 422 for fields the form does not have', new ApiError(422, { errors: { other: ['x'] } })],
    ['another status', new ApiError(429, { message: 'Too many.' })],
    ['a network failure', new NetworkError()],
  ])('applies nothing for %s', (_, error) => {
    const setError = vi.fn()

    expect(applyFieldErrors(error, setError, ['email'])).toBe(false)
    expect(setError).not.toHaveBeenCalled()
  })
})

describe('errorMessage', () => {
  it.each([
    [new NetworkError(), 'Cannot reach the server. Is the API running?'],
    [new ApiError(429, { message: 'Too Many Attempts.' }), 'Too many attempts. Wait a minute and try again.'],
    [new ApiError(403, { message: 'This action is unauthorized.' }), 'This action is unauthorized.'],
    [new ApiError(500, null), 'Something went wrong. Please try again.'],
    [new ApiError(500, { message: 'SQLSTATE[42P01]: relation "users" does not exist' }), 'Something went wrong. Please try again.'],
    [new Error('boom'), 'Something went wrong. Please try again.'],
  ])('%#: says %s', (error, message) => {
    expect(errorMessage(error)).toBe(message)
  })
})
