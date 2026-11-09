import { afterEach, describe, expect, it, vi } from 'vitest'
import { json, mockFetch } from '@/test/next'
import { clientApiEvents, clientFetch } from './clientApi'
import { ApiError, NetworkError } from './errors'

const original = { ...clientApiEvents }
afterEach(() => Object.assign(clientApiEvents, original))

describe('clientFetch', () => {
  it('calls the /api proxy on this origin with the organization header', async () => {
    const calls = mockFetch({ 'GET /api/tasks/1/chat': () => json({ data: [] }) })

    await expect(clientFetch('/api/tasks/1/chat', { organizationId: 10 })).resolves.toEqual({ data: [] })
    expect(calls[0].url.href).toBe('http://localhost/api/tasks/1/chat')
    expect(new Headers(calls[0].init.headers).get('x-organization-id')).toBe('10')
  })

  it('on a 409 organization_changed, tells the page, and throws', async () => {
    clientApiEvents.onOrganizationChanged = vi.fn()
    mockFetch({ 'DELETE /api/tasks/1/chat': () => json({ message: 'Changed.', code: 'organization_changed' }, 409) })

    await expect(clientFetch('/api/tasks/1/chat', { method: 'DELETE', organizationId: 10 })).rejects.toBeInstanceOf(ApiError)
    expect(clientApiEvents.onOrganizationChanged).toHaveBeenCalledOnce()
  })

  it('on a 401, tells the page the session is over', async () => {
    clientApiEvents.onUnauthenticated = vi.fn()
    mockFetch({ 'GET /api/tasks/1/chat': () => json({ message: 'Unauthenticated.' }, 401) })

    await expect(clientFetch('/api/tasks/1/chat')).rejects.toMatchObject({ status: 401 })
    expect(clientApiEvents.onUnauthenticated).toHaveBeenCalledOnce()
  })

  it('a failed connection is a NetworkError', async () => {
    mockFetch({ 'GET /api/tasks/1/chat': () => Promise.reject(new TypeError('fetch failed')) })

    await expect(clientFetch('/api/tasks/1/chat')).rejects.toBeInstanceOf(NetworkError)
  })

  it('204 is undefined', async () => {
    mockFetch({ 'DELETE /api/tasks/1/chat': () => new Response(null, { status: 204 }) })

    await expect(clientFetch('/api/tasks/1/chat', { method: 'DELETE' })).resolves.toBeUndefined()
  })
})
