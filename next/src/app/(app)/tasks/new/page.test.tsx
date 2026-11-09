import { screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import type { User } from '@shared/types/api'
import { acme, ada, meMember } from '@/test/fixtures'
import { renderWithClient } from '@/test/render'
import NewTaskPage from './page'

const session = vi.hoisted(() => ({ user: null as User | null }))

vi.mock('@/lib/dal', () => ({ requireUser: async () => session.user }))
vi.mock('@/lib/tasks', () => ({ getMembers: async () => [meMember] }))
vi.mock('next/navigation', () => ({ useRouter: () => ({ replace: vi.fn(), refresh: vi.fn() }) }))
vi.mock('@/features/tasks/actions', () => ({ createTask: vi.fn(), updateTask: vi.fn() }))

async function renderPage(user: User) {
  session.user = user
  renderWithClient(await NewTaskPage())
}

describe('writing help on the New task page', () => {
  it('is offered with ai.use and a verified email', async () => {
    await renderPage({ ...ada, current_organization: { ...acme, permissions: ['tasks.create', 'ai.use'] } })

    expect(screen.getByRole('button', { name: 'Improve with AI' })).toBeInTheDocument()
  })

  it('is hidden without ai.use', async () => {
    await renderPage({ ...ada, current_organization: { ...acme, permissions: ['tasks.create'] } })

    expect(screen.queryByRole('button', { name: 'Improve with AI' })).not.toBeInTheDocument()
  })

  it('is hidden for an unverified user', async () => {
    await renderPage({ ...ada, email_verified: false, current_organization: { ...acme, permissions: ['tasks.create', 'ai.use'] } })

    expect(screen.queryByRole('button', { name: 'Improve with AI' })).not.toBeInTheDocument()
  })
})
