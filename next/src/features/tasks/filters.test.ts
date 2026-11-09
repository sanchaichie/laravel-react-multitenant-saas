import { describe, expect, it } from 'vitest'
import { filtersToSearchParams, parseFilters, parseTaskId, tasksHref, toURLSearchParams } from './filters'

describe('task filters', () => {
  it('reads the known filters from searchParams and drops anything else', () => {
    const params = toURLSearchParams({ status: 'done', priority: 'urgent', assignee: ['me', '7'], overdue: '1', page: '0', x: 'y' })

    expect(parseFilters(params)).toEqual({ status: 'done', assignee: 'me', overdue: true })
  })

  it('round-trips through the URL, leaving out the defaults', () => {
    const filters = { status: 'todo', search: 'report', sort: 'newest', page: 1 } as const

    expect(filtersToSearchParams(filters).toString()).toBe('status=todo&search=report')
    expect(tasksHref({})).toBe('/tasks')
    expect(tasksHref({ overdue: true, page: 2 })).toBe('/tasks?overdue=1&page=2')
  })

  it('accepts only ids the API accepts', () => {
    expect(parseTaskId('42')).toBe(42)
    expect(parseTaskId('042')).toBeNull()
    expect(parseTaskId('4x')).toBeNull()
    expect(parseTaskId('1234567890123456789')).toBeNull()
  })
})
