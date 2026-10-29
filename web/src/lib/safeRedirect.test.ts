import { describe, expect, it } from 'vitest'
import { safeRedirect } from './safeRedirect'

describe('safeRedirect', () => {
  it.each(['/', '/tasks', '/tasks/3?tab=chat', '/join/abc#top'])('keeps the path %s', (path) => {
    expect(safeRedirect(path)).toBe(path)
  })

  it.each([
    ['protocol-relative', '//evil.example'],
    ['backslash', '/\\evil.example'],
    ['tab-split', '/\t/evil.example'],
    ['newline-split', '/\n/evil.example'],
    ['backslash later', '/a\\..\\/evil.example'],
    ['absolute', 'https://evil.example/login'],
    ['javascript', 'javascript:alert(1)'],
    ['relative', 'tasks'],
    ['empty', ''],
  ])('falls back to / for a %s value', (_, value) => {
    expect(safeRedirect(value)).toBe('/')
  })

  it.each([null, undefined, 42, ['/tasks']])('falls back to / for a non-string (%s)', (value) => {
    expect(safeRedirect(value)).toBe('/')
  })
})
