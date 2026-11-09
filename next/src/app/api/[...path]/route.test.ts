import { NextRequest } from 'next/server'
import { describe, expect, it, vi } from 'vitest'
import { json, mockFetch } from '@/test/next'
import { GET, POST } from './route'

function call(handler: typeof GET, url: string, init: ConstructorParameters<typeof NextRequest>[1] = {}, path: string[] = ['tasks']) {
  return handler(new NextRequest(url, init), { params: Promise.resolve({ path }) })
}

describe('the /api proxy', () => {
  it('forwards to Laravel with the token from the cookie, the query and X-Organization-Id', async () => {
    const calls = mockFetch({ 'GET /api/tasks': () => json({ data: [] }) })

    const response = await call(GET, 'http://localhost/api/tasks?status=todo', {
      headers: { cookie: 'session=secret-token; other=1', 'x-organization-id': '7', accept: 'application/json' },
    })

    expect(response.status).toBe(200)
    expect(await response.json()).toEqual({ data: [] })
    expect(calls).toHaveLength(1)
    expect(calls[0].url.href).toBe('http://api.test/api/tasks?status=todo')
    const headers = new Headers(calls[0].init.headers)
    expect(headers.get('authorization')).toBe('Bearer secret-token')
    expect(headers.get('x-organization-id')).toBe('7')
    // The browser's cookies stay here.
    expect(headers.get('cookie')).toBeNull()
  })

  it('passes the status and body through, and drops the cookie on a 401', async () => {
    mockFetch({ 'GET /api/tasks': () => json({ message: 'Unauthenticated.' }, 401) })

    const response = await call(GET, 'http://localhost/api/tasks', { headers: { cookie: 'session=old-token' } })

    expect(response.status).toBe(401)
    expect(await response.json()).toEqual({ message: 'Unauthenticated.' })
    expect(response.headers.get('set-cookie')).toMatch(/^session=;/)
  })

  it('sends no token without a cookie', async () => {
    const calls = mockFetch({ 'GET /api/tasks': () => json({ message: 'Unauthenticated.' }, 401) })

    const response = await call(GET, 'http://localhost/api/tasks')

    expect(new Headers(calls[0].init.headers).get('authorization')).toBeNull()
    // Nothing to clear.
    expect(response.headers.get('set-cookie')).toBeNull()
  })

  it('refuses the routes that hand out a token: only the Server Actions call them', async () => {
    const calls = mockFetch({})

    const response = await call(POST, 'http://localhost/api/login', { method: 'POST', body: '{}' }, ['login'])

    expect(response.status).toBe(404)
    expect(calls).toHaveLength(0)
  })

  it('refuses a write from another site', async () => {
    const calls = mockFetch({})

    const response = await call(POST, 'http://localhost/api/tasks', {
      method: 'POST',
      body: '{}',
      headers: { cookie: 'session=secret-token', origin: 'https://evil.example' },
    })

    expect(response.status).toBe(403)
    expect(calls).toHaveLength(0)
  })

  it('forwards a same-site write with its body', async () => {
    const calls = mockFetch({ 'POST /api/tasks': () => json({ data: { id: 1 } }, 201) })

    const response = await call(POST, 'http://localhost/api/tasks', {
      method: 'POST',
      body: JSON.stringify({ title: 'Ship it' }),
      headers: { cookie: 'session=secret-token', origin: 'http://localhost', 'content-type': 'application/json' },
    })

    expect(response.status).toBe(201)
    expect(new TextDecoder().decode(calls[0].init.body as ArrayBuffer)).toBe('{"title":"Ship it"}')
  })

  it('answers 502 when the API cannot be reached', async () => {
    mockFetch({ 'GET /api/tasks': () => Promise.reject(new TypeError('fetch failed')) })

    const response = await call(GET, 'http://localhost/api/tasks', { headers: { cookie: 'session=t' } })

    expect(response.status).toBe(502)
  })
})

describe('the /api proxy and dot segments', () => {
  it.each([[['.', 'login']], [['tasks', '..', 'login']], [['', 'login']]])('refuses %j before calling the API', async (path) => {
    const calls = mockFetch({})

    const response = await call(POST, 'http://localhost/api/x', { method: 'POST', body: '{}' }, path)

    expect(response.status).toBe(404)
    expect(calls).toHaveLength(0)
  })
})

describe('what the proxy lets through', () => {
  it('refuses API areas this app does not use, such as an admin view-as-user (it returns a token)', async () => {
    const calls = mockFetch({})

    const response = await call(POST, 'http://localhost/api/admin/users/42/impersonate', { method: 'POST', body: '{}' }, [
      'admin',
      'users',
      '42',
      'impersonate',
    ])

    expect(response.status).toBe(404)
    expect(calls).toHaveLength(0)
  })

  it('refuses a body over 1 MB', async () => {
    const calls = mockFetch({})

    const response = await call(POST, 'http://localhost/api/tasks', {
      method: 'POST',
      body: 'x'.repeat(1024 * 1024 + 1),
      headers: { cookie: 'session=t', 'content-type': 'application/json' },
    })

    expect(response.status).toBe(413)
    expect(calls).toHaveLength(0)
  })

  it('compares the Origin with the Host, which a TLS proxy in front keeps', async () => {
    mockFetch({ 'POST /api/tasks': () => json({ data: { id: 1 } }, 201) })

    const response = await call(POST, 'http://localhost:3100/api/tasks', {
      method: 'POST',
      body: '{}',
      headers: { cookie: 'session=t', origin: 'https://app.example.com', 'x-forwarded-host': 'app.example.com' },
    })

    expect(response.status).toBe(201)
  })
})

describe('the client IP', () => {
  it("passes on the proxy's client address, never the client's own X-Forwarded-For", async () => {
    vi.stubEnv('CLIENT_IP_HEADER', 'x-real-ip')
    const calls = mockFetch({ 'GET /api/tasks': () => json({ data: [] }) })

    await call(GET, 'http://localhost/api/tasks', { headers: { 'x-real-ip': '203.0.113.9', 'x-forwarded-for': '6.6.6.6' } })

    expect(new Headers(calls[0].init.headers).get('x-forwarded-for')).toBe('203.0.113.9')
    vi.unstubAllEnvs()
  })

  it('sends none when no proxy header is configured', async () => {
    const calls = mockFetch({ 'GET /api/tasks': () => json({ data: [] }) })

    await call(GET, 'http://localhost/api/tasks', { headers: { 'x-forwarded-for': '6.6.6.6' } })

    expect(new Headers(calls[0].init.headers).get('x-forwarded-for')).toBeNull()
  })
})
