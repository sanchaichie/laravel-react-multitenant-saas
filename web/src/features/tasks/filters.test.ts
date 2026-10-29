import { describe, expect, it } from 'vitest'
import { filtersToSearchParams, hasActiveFilters, parseFilters } from './filters'

const parse = (query: string) => parseFilters(new URLSearchParams(query))

describe('task filters in the URL', () => {
  it('reads the known filters', () => {
    expect(parse('status=in_progress&priority=low&assignee=12&overdue=1&search=+launch+&sort=priority&page=3')).toEqual({
      status: 'in_progress',
      priority: 'low',
      assignee: '12',
      overdue: true,
      search: 'launch',
      sort: 'priority',
      page: 3,
    })
    expect(parse('assignee=me')).toEqual({ assignee: 'me' })
    expect(parse('assignee=none')).toEqual({ assignee: 'none' })
  })

  it('drops unknown or malformed values', () => {
    expect(parse('status=blocked&priority=urgent&assignee=-1&overdue=yes&sort=title&page=0&search=%20%20')).toEqual({})
    expect(parse('page=1.5')).toEqual({})
    expect(parse('page=1')).toEqual({})
    expect(parse('assignee=0')).toEqual({})
    expect(parse('assignee=1234567890123456789')).toEqual({})
    expect(parse(`search=${'a'.repeat(150)}`).search).toHaveLength(100)
  })

  it('writes them back, leaving out the defaults', () => {
    expect(filtersToSearchParams({ status: 'done', overdue: true, sort: 'newest', page: 1 }).toString()).toBe('status=done&overdue=1')
    expect(filtersToSearchParams({ search: 'a&b', page: 2, sort: 'due' }).toString()).toBe('search=a%26b&sort=due&page=2')
    expect(filtersToSearchParams({}).toString()).toBe('')
  })

  it('round-trips', () => {
    const query = 'status=todo&priority=high&assignee=me&overdue=1&search=x&sort=due&page=2'
    expect(filtersToSearchParams(parse(query)).toString()).toBe(query)
  })

  it('knows when something narrows the list', () => {
    expect(hasActiveFilters({ sort: 'due', page: 3 })).toBe(false)
    expect(hasActiveFilters({ overdue: true })).toBe(true)
    expect(hasActiveFilters({ search: 'x' })).toBe(true)
  })
})
