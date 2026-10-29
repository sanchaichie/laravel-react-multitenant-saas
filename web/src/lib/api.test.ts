import { waitFor } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { queryClient } from '@/lib/queryClient'
import { useAuth } from '@/stores/auth'
import { acme, ada, beta, jsonResponse, logIn, mockApi } from '@/test/utils'
import { ApiError, apiFetch, NetworkError } from './api'

describe('apiFetch', () => {
  it('sends the base URL, JSON headers and the token', async () => {
    logIn(ada, '1|abc')
    const fetchMock = mockApi({ 'POST /api/things': () => jsonResponse({ ok: true }) })

    await expect(apiFetch('/api/things', { method: 'POST', body: { a: 1 } })).resolves.toEqual({ ok: true })

    const [url, init] = fetchMock.mock.calls[0]
    expect(String(url)).toBe('http://api.test/api/things')
    expect(init?.headers).toEqual({
      Accept: 'application/json',
      'Content-Type': 'application/json',
      Authorization: 'Bearer 1|abc',
      'X-Organization-Id': String(acme.id),
    })
    expect(init?.body).toBe('{"a":1}')
  })

  it('says which organization this tab thinks is current (X-Organization-Id)', async () => {
    logIn()
    const fetchMock = mockApi({ 'GET /api/things': () => jsonResponse([]) })

    await apiFetch('/api/things')

    expect(fetchMock.mock.calls[0][1]?.headers).toMatchObject({ 'X-Organization-Id': String(acme.id) })
  })

  it('catches up when another tab switched organization (409 organization_changed)', async () => {
    logIn()
    queryClient.setQueryData(['org', acme.id, 'tasks'], ['Acme task'])
    const fetchMock = mockApi({
      'GET /api/org-thing': () =>
        jsonResponse({ message: 'Your current organization changed.', code: 'organization_changed' }, 409),
      'GET /api/me': () => jsonResponse({ data: { ...ada, current_organization: beta } }),
    })

    await expect(apiFetch('/api/org-thing')).rejects.toMatchObject({ status: 409 })

    await waitFor(() => expect(useAuth.getState().user?.current_organization).toEqual(beta))
    expect(queryClient.getQueryData(['org', acme.id, 'tasks'])).toBeUndefined()
    expect(fetchMock.mock.calls.filter(([url]) => String(url).endsWith('/api/me'))).toHaveLength(1)
  })

  it('sends no token or Content-Type when there is none', async () => {
    const fetchMock = mockApi({ 'GET /api/things': () => jsonResponse([]) })

    await apiFetch('/api/things')

    expect(fetchMock.mock.calls[0][1]?.headers).toEqual({ Accept: 'application/json' })
  })

  it('returns nothing for a 204', async () => {
    mockApi({ 'DELETE /api/things/1': () => jsonResponse(null, 204) })

    await expect(apiFetch('/api/things/1', { method: 'DELETE' })).resolves.toBeUndefined()
  })

  it('throws an ApiError with the body on an error status', async () => {
    mockApi({
      'POST /api/things': () => jsonResponse({ message: 'Invalid.', errors: { title: ['Required.'] } }, 422),
    })

    const error = await apiFetch('/api/things', { method: 'POST', body: {} }).catch((e: unknown) => e)

    expect(error).toBeInstanceOf(ApiError)
    expect(error).toMatchObject({ status: 422, data: { errors: { title: ['Required.'] } } })
  })

  it('throws a NetworkError when the API cannot be reached', async () => {
    vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new TypeError('Failed to fetch')))

    await expect(apiFetch('/api/me')).rejects.toBeInstanceOf(NetworkError)
  })

  it('counts a timeout as the API not answering, but passes on a cancel', async () => {
    vi.stubGlobal('fetch', vi.fn((_: unknown, init: RequestInit) => new Promise((_resolve, reject) => {
      init.signal?.addEventListener('abort', () => reject(init.signal?.reason))
    })))

    await expect(apiFetch('/api/me', { signal: AbortSignal.timeout(5) })).rejects.toBeInstanceOf(NetworkError)

    const controller = new AbortController()
    const request = apiFetch('/api/me', { signal: controller.signal })
    controller.abort()
    await expect(request).rejects.toMatchObject({ name: 'AbortError' })
  })

  it('leaves a 401 to the caller when asked', async () => {
    logIn()
    mockApi({ 'POST /api/logout': () => jsonResponse({ message: 'Unauthenticated.' }, 401) })

    await expect(apiFetch('/api/logout', { method: 'POST', endSessionOn401: false })).rejects.toMatchObject({ status: 401 })

    expect(useAuth.getState().token).not.toBeNull()
  })

  it('ends the session and drops cached data on a 401 for the current token', async () => {
    logIn()
    queryClient.setQueryData(['org', 1, 'tasks'], ['cached'])
    mockApi({ 'GET /api/me': () => jsonResponse({ message: 'Unauthenticated.' }, 401) })

    await expect(apiFetch('/api/me')).rejects.toMatchObject({ status: 401 })

    expect(useAuth.getState()).toMatchObject({ token: null, user: null })
    expect(queryClient.getQueryData(['org', 1, 'tasks'])).toBeUndefined()
  })

  it('keeps a newer session when an older token gets a 401', async () => {
    logIn(ada, '1|old')
    mockApi({
      'GET /api/me': () => {
        // Logged in again while this request was on its way.
        logIn(ada, '2|new')
        return jsonResponse({ message: 'Unauthenticated.' }, 401)
      },
    })

    await expect(apiFetch('/api/me')).rejects.toMatchObject({ status: 401 })

    expect(useAuth.getState().token).toBe('2|new')
  })
})
