import { screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { useAuth } from '@/stores/auth'
import { ada, jsonResponse, mockApi, renderApp, sentBody } from '@/test/utils'

async function fillIn(user: ReturnType<typeof renderApp>['user'], values: { name?: string; email?: string; password?: string; confirmation?: string }) {
  await user.type(screen.getByLabelText('Name'), values.name ?? 'Ada Lovelace')
  await user.type(screen.getByLabelText('Email'), values.email ?? 'ada@example.com')
  await user.type(screen.getByLabelText('Password'), values.password ?? 'secret-password')
  await user.type(screen.getByLabelText('Confirm password'), values.confirmation ?? values.password ?? 'secret-password')
}

describe('RegisterForm', () => {
  it('catches mismatched passwords before sending', async () => {
    const fetchMock = mockApi({})
    const { user } = renderApp('/register')

    await fillIn(user, { password: 'secret-password', confirmation: 'other-password' })
    await user.click(screen.getByRole('button', { name: 'Create account' }))

    expect(await screen.findByText('The passwords don\'t match.')).toBeInTheDocument()
    expect(fetchMock).not.toHaveBeenCalled()
  })

  it('asks for at least 8 characters', async () => {
    mockApi({})
    const { user } = renderApp('/register')

    await fillIn(user, { password: 'short' })
    await user.click(screen.getByRole('button', { name: 'Create account' }))

    // The error replaces the hint, and the input points screen readers to it.
    const error = await screen.findByText('At least 8 characters.', { selector: '[role="alert"]' })
    expect(screen.getAllByText('At least 8 characters.')).toHaveLength(1)
    expect(screen.getByLabelText('Password')).toHaveAccessibleDescription('At least 8 characters.')
    expect(screen.getByLabelText('Password')).toHaveAttribute('aria-describedby', error.id)
  })

  it('links every error to its field', async () => {
    mockApi({})
    const { user } = renderApp('/register')

    await user.click(screen.getByRole('button', { name: 'Create account' }))

    expect(await screen.findByLabelText('Name')).toHaveAccessibleDescription('Enter your name.')
    expect(screen.getByLabelText('Email')).toHaveAccessibleDescription('Enter your email.')
    expect(screen.getByLabelText('Confirm password')).toHaveAccessibleDescription('Repeat your password.')
    expect(screen.getByLabelText('Name')).toHaveFocus()
  })

  it('shows "email taken" from the API under the email field', async () => {
    mockApi({
      'POST /api/register': () =>
        jsonResponse({ message: 'The email has already been taken.', errors: { email: ['The email has already been taken.'] } }, 422),
    })
    const { user } = renderApp('/register')

    await fillIn(user, {})
    await user.click(screen.getByRole('button', { name: 'Create account' }))

    expect(await screen.findByText('The email has already been taken.')).toBeInTheDocument()
    expect(screen.getByLabelText('Email')).toHaveFocus()
  })

  it('creates the account (email in lowercase) and lands home logged in', async () => {
    const fetchMock = mockApi({ 'POST /api/register': () => jsonResponse({ user: ada, token: '1|new' }, 201) })
    const { user } = renderApp('/register')

    await fillIn(user, { email: 'Ada@Example.com' })
    await user.click(screen.getByRole('button', { name: 'Create account' }))

    expect(await screen.findByRole('heading', { name: 'Welcome, Ada Lovelace' })).toBeInTheDocument()
    expect(useAuth.getState().token).toBe('1|new')
    expect(sentBody(fetchMock.mock.calls[0][1] ?? {})).toMatchObject({ email: 'ada@example.com' })
  })
})
