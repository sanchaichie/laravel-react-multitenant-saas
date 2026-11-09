import { afterEach, describe, expect, it, vi } from 'vitest'
import { clientIp } from './clientIp'

afterEach(() => vi.unstubAllEnvs())

describe('clientIp', () => {
  it('sends nothing unless a proxy header is configured', () => {
    expect(clientIp(new Headers({ 'x-forwarded-for': '203.0.113.9' }))).toBeNull()
  })

  it('takes the last entry of the configured header: the one the proxy added', () => {
    vi.stubEnv('CLIENT_IP_HEADER', 'X-Forwarded-For')

    expect(clientIp(new Headers({ 'x-forwarded-for': '6.6.6.6, 203.0.113.9' }))).toBe('203.0.113.9')
    expect(clientIp(new Headers({ 'x-forwarded-for': '2001:db8::1' }))).toBe('2001:db8::1')
  })

  it('ignores anything that is not an address', () => {
    vi.stubEnv('CLIENT_IP_HEADER', 'x-real-ip')

    expect(clientIp(new Headers({ 'x-real-ip': 'evil.example' }))).toBeNull()
    expect(clientIp(new Headers())).toBeNull()
  })
})
