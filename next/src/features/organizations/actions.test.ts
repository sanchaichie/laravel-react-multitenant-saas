import { describe, expect, it, vi } from 'vitest'
import { json, mockFetch, nextCache, RedirectError } from '@/test/next'
import { switchOrganization } from './actions'

vi.mock('next/headers', async () => (await import('@/test/next')).nextHeaders)
vi.mock('next/navigation', async () => (await import('@/test/next')).nextNavigation)
vi.mock('next/cache', async () => (await import('@/test/next')).nextCache)

describe('switching organization', () => {
  it('goes to the section root and drops the cached pages', async () => {
    nextCache.revalidatePath.mockClear()
    const calls = mockFetch({ 'POST /api/organizations/20/switch': () => json({ data: { id: 20 } }) })

    await expect(switchOrganization(20, '/tasks/42')).rejects.toEqual(new RedirectError('/tasks'))

    expect(calls).toHaveLength(1)
    expect(nextCache.revalidatePath).toHaveBeenCalledWith('/', 'layout')
  })

  it('says why on failure and keeps the page', async () => {
    nextCache.revalidatePath.mockClear()
    mockFetch({ 'POST /api/organizations/99/switch': () => json({ message: 'Not found.' }, 404) })

    await expect(switchOrganization(99, '/tasks')).resolves.toEqual({ message: 'Not found.' })
    expect(nextCache.revalidatePath).not.toHaveBeenCalled()
  })
})
