import { act, screen, waitFor, within } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { makeTask, makeTaskSummary, meMember, noSummary } from '@/test/tasks'
import { acme, beta, jsonResponse, logIn, mockApi, organizationList, renderApp, sentBody } from '@/test/utils'
import type { TaskSummary, TaskSummaryResponse } from '@/types/api'

type Handler = (init: RequestInit, url: URL) => Response

const task = makeTask({ id: 42, title: 'Write onboarding doc', description: 'For new hires.' })

function api(summary: () => TaskSummaryResponse, overrides: Record<string, Handler> = {}) {
  return mockApi({
    'GET /api/organizations': () => jsonResponse(organizationList([acme, beta])),
    'GET /api/members': () => jsonResponse({ data: [meMember] }),
    'GET /api/tasks/42': () => jsonResponse({ data: task }),
    'GET /api/tasks/42/summary': () => jsonResponse(summary()),
    ...overrides,
  })
}

function response(data: TaskSummary | null, approved: TaskSummary | null = null, regenerate = true): TaskSummaryResponse {
  return { data, approved, can: { regenerate } }
}

async function card() {
  return (await screen.findByRole('heading', { name: 'AI summary' })).closest('[data-slot="card"]') as HTMLElement
}

afterEach(() => {
  vi.useRealTimers()
})

describe('TaskSummaryCard', () => {
  it('says there is none yet, and generates one, polling until the draft is ready', async () => {
    vi.useFakeTimers({ shouldAdvanceTime: true })
    logIn()
    const pending = makeTaskSummary({ id: 5, status: 'pending', summary: null, key_points: [], model: null })
    const draft = { ...pending, status: 'draft' as const, summary: 'A doc for new hires.', key_points: ['Week one'] }
    let current: TaskSummaryResponse = noSummary
    const fetchMock = api(() => current, {
      'POST /api/tasks/42/summary': () => {
        current = response(pending)
        return jsonResponse({ data: pending }, 202)
      },
    })
    const { user } = renderApp('/tasks/42')

    const summaryCard = await card()
    expect(await within(summaryCard).findByText('No summary yet.')).toBeInTheDocument()
    await user.click(within(summaryCard).getByRole('button', { name: 'Generate summary' }))

    expect(await within(summaryCard).findByText('Generating…')).toBeInTheDocument()
    // No Regenerate while one is being generated.
    expect(within(summaryCard).queryByRole('button', { name: /Regenerate/ })).not.toBeInTheDocument()

    current = response(draft)
    await act(() => vi.advanceTimersByTimeAsync(3_000))

    expect(await within(summaryCard).findByText('A doc for new hires.')).toBeInTheDocument()
    expect(within(summaryCard).getByText('AI draft')).toBeInTheDocument()
    expect(within(summaryCard).getByText('Week one')).toBeInTheDocument()

    // Ready: polling stops.
    const gets = () => fetchMock.mock.calls.filter(([url, init]) => String(url).endsWith('/summary') && !init?.method).length
    const before = gets()
    await act(() => vi.advanceTimersByTimeAsync(10_000))
    expect(gets()).toBe(before)
  })

  it('stops waiting after a minute and says to check back', async () => {
    vi.useFakeTimers({ shouldAdvanceTime: true })
    logIn()
    const pending = makeTaskSummary({ status: 'pending', summary: null, key_points: [] })
    const fetchMock = api(() => response(pending))
    renderApp('/tasks/42')

    const summaryCard = await card()
    expect(await within(summaryCard).findByText('Generating…')).toBeInTheDocument()

    await act(() => vi.advanceTimersByTimeAsync(60_000))

    expect(within(summaryCard).getByText('Still working, check back soon.')).toBeInTheDocument()
    // And stops asking.
    const gets = () => fetchMock.mock.calls.filter(([url, init]) => String(url).endsWith('/summary') && !init?.method).length
    const before = gets()
    await act(() => vi.advanceTimersByTimeAsync(10_000))
    expect(gets()).toBe(before)
  })

  it('offers Regenerate for a summary stuck in pending', async () => {
    logIn()
    const stuck = makeTaskSummary({ id: 11, status: 'pending', summary: null, key_points: [], stuck: true })
    const fresh = makeTaskSummary({ id: 12, status: 'pending', summary: null, key_points: [] })
    let current = response(stuck)
    const fetchMock = api(() => current, {
      'POST /api/tasks/42/summary': () => {
        current = response(fresh)
        return jsonResponse({ data: fresh }, 202)
      },
    })
    const { user } = renderApp('/tasks/42')

    const summaryCard = await card()
    expect(await within(summaryCard).findByText('Generating…')).toBeInTheDocument()
    await user.click(within(summaryCard).getByRole('button', { name: 'Regenerate' }))

    await waitFor(() => expect(within(summaryCard).queryByRole('button', { name: 'Regenerate' })).not.toBeInTheDocument())
    expect(fetchMock.mock.calls.some(([, init]) => init?.method === 'POST')).toBe(true)
  })

  it('approves a draft', async () => {
    logIn()
    const draft = makeTaskSummary({ id: 6 })
    api(() => response(draft), {
      'POST /api/tasks/42/summaries/6/approve': () =>
        jsonResponse({
          data: { ...draft, status: 'approved', reviewed_by: { id: 1, name: 'Ada Lovelace' }, reviewed_at: '2026-10-22T09:00:00Z' },
        }),
    })
    const { user } = renderApp('/tasks/42')

    const summaryCard = await card()
    await user.click(await within(summaryCard).findByRole('button', { name: 'Approve' }))

    expect(await within(summaryCard).findByText('Approved by Ada Lovelace on Oct 22, 2026')).toBeInTheDocument()
    expect(within(summaryCard).queryByText('AI draft')).not.toBeInTheDocument()
    expect(screen.getByText('Summary approved')).toBeInTheDocument()
  })

  it('rejects a draft, keeping the approved summary on show', async () => {
    logIn()
    const approved = makeTaskSummary({
      id: 3,
      status: 'approved',
      summary: 'The approved summary.',
      reviewed_by: { id: 1, name: 'Ada Lovelace' },
      reviewed_at: '2026-10-20T09:00:00Z',
    })
    const draft = makeTaskSummary({ id: 7, summary: 'A newer draft.' })
    api(() => response(draft, approved), {
      'POST /api/tasks/42/summaries/7/reject': () => jsonResponse({ data: { ...draft, status: 'rejected' } }),
    })
    const { user } = renderApp('/tasks/42')

    const summaryCard = await card()
    expect(await within(summaryCard).findByText('A newer draft.')).toBeInTheDocument()
    expect(within(summaryCard).getByRole('region', { name: 'Approved summary' })).toHaveTextContent('The approved summary.')

    await user.click(within(summaryCard).getByRole('button', { name: 'Reject' }))

    expect(await within(summaryCard).findByText('The last draft was rejected.')).toBeInTheDocument()
    expect(within(summaryCard).getByRole('region', { name: 'Approved summary' })).toHaveTextContent('The approved summary.')
  })

  it('edits a draft before approving it, sending only the edited text', async () => {
    logIn()
    const draft = makeTaskSummary({ id: 8, summary: 'Old text.', key_points: ['Keep me', 'Drop me'] })
    const fetchMock = api(() => response(draft), {
      'POST /api/tasks/42/summaries/8/approve': (init) => {
        const edits = sentBody(init) as { summary: string; key_points: string[] }
        return jsonResponse({ data: { ...draft, ...edits, status: 'approved', reviewed_at: '2026-10-22T09:00:00Z' } })
      },
    })
    const { user } = renderApp('/tasks/42')

    const summaryCard = await card()
    await user.click(await within(summaryCard).findByRole('button', { name: 'Edit' }))
    const form = within(summaryCard).getByRole('form', { name: 'Edit summary' })

    await user.clear(within(form).getByLabelText('Summary'))
    await user.type(within(form).getByLabelText('Summary'), '  New text.  ')
    await user.click(within(form).getByRole('button', { name: 'Remove key point 2' }))
    await user.click(within(form).getByRole('button', { name: 'Add key point' }))
    await user.type(within(form).getByLabelText('Key point 2'), 'Added')
    await user.click(within(form).getByRole('button', { name: 'Approve with changes' }))

    expect(await within(summaryCard).findByText('New text.')).toBeInTheDocument()
    expect(within(summaryCard).queryByRole('form')).not.toBeInTheDocument()
    const post = fetchMock.mock.calls.find(([url]) => String(url).endsWith('/approve'))
    expect(sentBody(post?.[1] ?? {})).toEqual({ summary: 'New text.', key_points: ['Keep me', 'Added'] })
  })

  it('checks the edits before sending them', async () => {
    logIn()
    const fetchMock = api(() => response(makeTaskSummary({ id: 9, key_points: [] })))
    const { user } = renderApp('/tasks/42')

    const summaryCard = await card()
    await user.click(await within(summaryCard).findByRole('button', { name: 'Edit' }))
    const form = within(summaryCard).getByRole('form', { name: 'Edit summary' })
    await user.clear(within(form).getByLabelText('Summary'))
    await user.click(within(form).getByRole('button', { name: 'Add key point' }))
    await user.click(within(form).getByRole('button', { name: 'Approve with changes' }))

    expect(await within(form).findByText('Enter a summary.')).toBeInTheDocument()
    expect(within(form).getByText('Enter a point, or remove it.')).toBeInTheDocument()
    // At most 5 points: the Add button goes away at the limit.
    for (let i = 0; i < 4; i++) await user.click(within(form).getByRole('button', { name: 'Add key point' }))
    expect(within(form).queryByRole('button', { name: 'Add key point' })).not.toBeInTheDocument()
    expect(fetchMock.mock.calls.some(([url]) => String(url).endsWith('/approve'))).toBe(false)
  })

  it('puts the API\'s 422 errors on the matching inputs and keeps the editor open', async () => {
    logIn()
    api(() => response(makeTaskSummary({ id: 10, key_points: ['One', 'Two'] })), {
      'POST /api/tasks/42/summaries/10/approve': () =>
        jsonResponse({ message: 'Invalid.', errors: { 'key_points.1': ['The point is too long.'] } }, 422),
    })
    const { user } = renderApp('/tasks/42')

    const summaryCard = await card()
    await user.click(await within(summaryCard).findByRole('button', { name: 'Edit' }))
    const form = within(summaryCard).getByRole('form', { name: 'Edit summary' })
    await user.click(within(form).getByRole('button', { name: 'Approve with changes' }))

    expect(await within(form).findByText('The point is too long.')).toBeInTheDocument()
    expect(within(form).getByLabelText('Key point 2')).toHaveAttribute('aria-invalid', 'true')
    expect(within(form).getByLabelText('Key point 2')).toHaveFocus()
  })

  it('shows why a summary failed', async () => {
    logIn()
    api(() => response(makeTaskSummary({ status: 'failed', summary: null, key_points: [], error: 'The AI declined to summarize this task.' })))
    renderApp('/tasks/42')

    const summaryCard = await card()
    expect(await within(summaryCard).findByText('The AI declined to summarize this task.')).toBeInTheDocument()
    expect(within(summaryCard).getByRole('button', { name: 'Regenerate' })).toBeInTheDocument()
  })

  it('says when the task changed since the summary', async () => {
    logIn()
    api(() =>
      response(
        makeTaskSummary({ status: 'approved', stale: true, reviewed_by: { id: 1, name: 'Ada Lovelace' }, reviewed_at: '2026-10-20T09:00:00Z' }),
      ),
    )
    renderApp('/tasks/42')

    const summaryCard = await card()
    expect(await within(summaryCard).findByText('The task changed since this summary was written.')).toBeInTheDocument()
  })

  it('shows no buttons to someone who may not review or request', async () => {
    logIn()
    api(() => response(makeTaskSummary({ can: { review: false } }), null, false))
    renderApp('/tasks/42')

    const summaryCard = await card()
    expect(await within(summaryCard).findByText('AI draft')).toBeInTheDocument()
    expect(within(summaryCard).queryByRole('button')).not.toBeInTheDocument()
  })

  it('says when the organization asked for too many summaries', async () => {
    logIn()
    api(() => noSummary, {
      'POST /api/tasks/42/summary': () => jsonResponse({ message: 'Too many AI requests for this organization. Try again in a minute.' }, 429),
    })
    const { user } = renderApp('/tasks/42')

    const summaryCard = await card()
    await user.click(await within(summaryCard).findByRole('button', { name: 'Generate summary' }))

    expect(await screen.findByText('Could not request a summary')).toBeInTheDocument()
    expect(screen.getByText('Too many AI requests for this organization, try again in a minute.')).toBeInTheDocument()
    await waitFor(() => expect(within(summaryCard).getByText('No summary yet.')).toBeInTheDocument())
  })
})
