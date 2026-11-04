import { screen, waitFor } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { useAuth } from '@/stores/auth'
import { ada, jsonResponse, mockApi, renderApp, sentBody } from '@/test/utils'

const challengeAnswer = () => jsonResponse({ two_factor: true, challenge: 'challenge-1' })

function invalid(field: string, message: string) {
  return jsonResponse({ message, errors: { [field]: [message] } }, 422)
}

async function passwordStep(user: ReturnType<typeof renderApp>['user']) {
  await user.type(screen.getByLabelText('Email'), 'ada@example.com')
  await user.type(screen.getByLabelText('Password'), 'secret-password')
  await user.click(screen.getByRole('button', { name: 'Log in' }))
  return screen.findByLabelText('Code from your authenticator app')
}

/** The body of the last call to `path`. */
function lastBody(fetchMock: ReturnType<typeof mockApi>, path: string) {
  const call = fetchMock.mock.calls.filter(([url]) => String(url).includes(path)).at(-1)
  return sentBody(call?.[1] ?? {})
}

describe('Login with two-factor', () => {
  it('asks for a code after the password, sends it once all six digits are in, and logs in', async () => {
    const fetchMock = mockApi({
      'POST /api/login': challengeAnswer,
      'POST /api/two-factor-challenge': () => jsonResponse({ user: { ...ada, two_factor_enabled: true }, token: '1|2fa' }),
    })
    const { router, user } = renderApp('/login?redirect=%2Faccount')

    const code = await passwordStep(user)
    expect(screen.getByRole('heading', { name: 'Two-factor authentication' })).toBeInTheDocument()
    expect(screen.getByText('Logging in as ada@example.com.')).toBeInTheDocument()
    expect(code).toHaveFocus()
    expect(useAuth.getState().token).toBeNull()

    // No button press: the sixth digit submits.
    await user.type(code, '123456')

    await waitFor(() => expect(useAuth.getState().token).toBe('1|2fa'))
    expect(lastBody(fetchMock, '/api/two-factor-challenge')).toEqual({ challenge: 'challenge-1', code: '123456' })
    await waitFor(() => expect(router.state.location.pathname).toBe('/account'))
  })

  it('shows a wrong code under the boxes and empties them', async () => {
    mockApi({
      'POST /api/login': challengeAnswer,
      'POST /api/two-factor-challenge': () => invalid('code', "That code isn't right. Check the time on your phone and try the newest code."),
    })
    const { user } = renderApp('/login')

    const code = await passwordStep(user)
    await user.type(code, '000000')

    expect(await screen.findByText(/That code isn't right/)).toBeInTheDocument()
    expect(code).toHaveValue('')
    expect(code).toHaveAttribute('aria-invalid', 'true')
    expect(useAuth.getState().token).toBeNull()
  })

  it('logs in with a recovery code instead', async () => {
    const fetchMock = mockApi({
      'POST /api/login': challengeAnswer,
      'POST /api/two-factor-challenge': () => jsonResponse({ user: ada, token: '1|recovery' }),
    })
    const { user } = renderApp('/login')

    await passwordStep(user)
    await user.click(screen.getByRole('button', { name: 'Use a recovery code instead' }))
    await user.type(screen.getByLabelText('Recovery code'), ' abcde-fghij ')
    await user.click(screen.getByRole('button', { name: 'Verify' }))

    await waitFor(() => expect(useAuth.getState().token).toBe('1|recovery'))
    expect(lastBody(fetchMock, '/api/two-factor-challenge')).toEqual({ challenge: 'challenge-1', recovery_code: 'abcde-fghij' })
  })

  it('goes back to the password, with the email kept, when the login expired', async () => {
    mockApi({
      'POST /api/login': challengeAnswer,
      'POST /api/two-factor-challenge': () => invalid('challenge', 'This login has expired. Enter your password again.'),
    })
    const { user } = renderApp('/login')

    const code = await passwordStep(user)
    await user.type(code, '123456')

    expect(await screen.findByText('This login has expired. Enter your password again.')).toBeInTheDocument()
    expect(screen.getByRole('heading', { name: 'Log in' })).toBeInTheDocument()
    expect(screen.getByLabelText('Email')).toHaveValue('ada@example.com')
    expect(screen.getByLabelText('Password')).toHaveValue('')
    expect(screen.getByLabelText('Password')).toHaveFocus()
  })

  it('goes back to the password step with Back', async () => {
    mockApi({ 'POST /api/login': challengeAnswer })
    const { user } = renderApp('/login')

    await passwordStep(user)
    await user.click(screen.getByRole('button', { name: 'Back' }))

    expect(screen.getByRole('heading', { name: 'Log in' })).toBeInTheDocument()
    expect(screen.getByLabelText('Email')).toHaveValue('ada@example.com')
  })
})

describe('Password reset with two-factor', () => {
  it('saves the new password, then asks for a code before logging in', async () => {
    const fetchMock = mockApi({
      'POST /api/reset-password': challengeAnswer,
      'POST /api/two-factor-challenge': () => jsonResponse({ user: ada, token: '1|after-reset' }),
    })
    const { user } = renderApp('/reset-password?token=abc123&email=ada%40example.com')

    await user.type(screen.getByLabelText('New password'), 'new-secret-password')
    await user.type(screen.getByLabelText('Repeat the new password'), 'new-secret-password')
    await user.click(screen.getByRole('button', { name: 'Set new password' }))

    expect(await screen.findByText('Your new password is saved. Enter a code to log in.')).toBeInTheDocument()
    expect(useAuth.getState().token).toBeNull()
    await user.type(screen.getByLabelText('Code from your authenticator app'), '654321')

    await waitFor(() => expect(useAuth.getState().token).toBe('1|after-reset'))
    expect(lastBody(fetchMock, '/api/two-factor-challenge')).toEqual({ challenge: 'challenge-1', code: '654321' })
  })

  it('sends you to log in when you leave the code step: the password is already changed', async () => {
    mockApi({ 'POST /api/reset-password': challengeAnswer })
    const { router, user } = renderApp('/reset-password?token=abc123&email=ada%40example.com')

    await user.type(screen.getByLabelText('New password'), 'new-secret-password')
    await user.type(screen.getByLabelText('Repeat the new password'), 'new-secret-password')
    await user.click(screen.getByRole('button', { name: 'Set new password' }))
    await user.click(await screen.findByRole('button', { name: 'Back' }))

    expect(screen.getByText('Your new password is saved')).toBeInTheDocument()
    await user.click(screen.getByRole('link', { name: 'Go to log in' }))
    expect(router.state.location.pathname).toBe('/login')
  })
})
