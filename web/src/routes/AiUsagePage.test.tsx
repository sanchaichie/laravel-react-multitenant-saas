import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { acme, ada, beta, jsonResponse, logIn, memberRole, mockApi, organizationList, renderApp, sentBody } from '@/test/utils'
import type { AiBudget, AiUsageReport, AiUsageRow } from '@/types/api'

type Handler = (init: RequestInit, url: URL) => Response

const row = (overrides: Partial<AiUsageRow> = {}): AiUsageRow => ({
  calls: 2,
  failed: 0,
  input_tokens: 1200,
  output_tokens: 300,
  cost_usd: 0.0003,
  ...overrides,
})

const budget = (overrides: Partial<AiBudget> = {}): AiBudget => ({
  limit_usd: 5,
  is_default: true,
  own_limit_usd: null,
  default_limit_usd: 5,
  plan: 'Free',
  credits_usd: 0,
  credits_expire_at: '2026-11-01T00:00:00Z',
  spent_usd: 1.2,
  remaining_usd: 3.8,
  ...overrides,
})

function report(overrides: Partial<AiUsageReport> = {}): { data: AiUsageReport } {
  return {
    data: {
      month: '2026-10',
      totals: row({ calls: 3, failed: 1, cost_usd: 1.2 }),
      by_feature: [{ feature: 'task_summary', ...row({ calls: 3, failed: 1, cost_usd: 1.2 }) }],
      by_user: [
        { user: { id: ada.id, name: ada.name }, ...row({ cost_usd: 1 }) },
        { user: null, ...row({ calls: 1, cost_usd: 0.2 }) },
      ],
      by_day: [{ day: '2026-10-29', ...row({ calls: 3 }) }],
      budget: budget(),
      can: { manage_budget: true },
      ...overrides,
    },
  }
}

function api(overrides: Record<string, Handler> = {}) {
  return mockApi({
    'GET /api/organizations': () => jsonResponse(organizationList([acme, beta])),
    'GET /api/ai-usage': () => jsonResponse(report()),
    ...overrides,
  })
}

const table = (name: string) => screen.findByRole('table', { name })

describe('AiUsagePage', () => {
  it('shows the budget, the totals and each breakdown', async () => {
    logIn()
    api()
    renderApp('/ai-usage')

    expect(await screen.findByRole('heading', { name: /Monthly budget/ })).toBeInTheDocument()
    expect(screen.getByText('$1.20 of $5.00 spent this month')).toBeInTheDocument()
    // 1.20 of 5.00: screen readers get the value, not just the label.
    expect(screen.getByRole('progressbar', { name: '$1.20 of $5.00 spent this month' })).toHaveAttribute('aria-valuenow', '24')
    expect(screen.getByText('Free plan')).toBeInTheDocument()

    const totals = screen.getByRole('region', { name: 'Totals' })
    expect(within(totals).getByText('Calls').nextSibling).toHaveTextContent('3')
    expect(within(totals).getByText('Cost').nextSibling).toHaveTextContent('$1.20')

    expect(within(await table('By feature')).getByText('Task summaries')).toBeInTheDocument()
    const people = await table('By person')
    expect(within(people).getByText(ada.name)).toBeInTheDocument()
    expect(within(people).getByText('No one (automatic, or a deleted account)')).toBeInTheDocument()
    // Under a cent keeps 4 decimals.
    expect(within(await table('By day')).getByText('Oct 29, 2026')).toBeInTheDocument()
    expect(within(await table('By day')).getByText('$0.0003')).toBeInTheDocument()
  })

  it('reads the month from the URL and changes it there', async () => {
    logIn()
    const fetchMock = api({
      'GET /api/ai-usage': (_init, url) =>
        jsonResponse(report({ month: url.searchParams.get('month') ?? '2026-10' })),
    })
    const { router } = renderApp('/ai-usage?month=2026-09')

    const picker = await screen.findByLabelText('Month')
    await waitFor(() => expect(picker).toHaveValue('2026-09'))

    // jsdom can't type into a month input: set its value as the browser's picker would.
    fireEvent.change(picker, { target: { value: '2026-08' } })
    await waitFor(() => expect(router.state.location.search).toBe('?month=2026-08'))

    // Emptied: this month, and no ?month= left.
    fireEvent.change(picker, { target: { value: '' } })
    await waitFor(() => expect(router.state.location.search).toBe(''))
    const months = fetchMock.mock.calls.map(([url]) => new URL(String(url)).searchParams.get('month'))
    expect(months).toContain('2026-09')
    expect(months).toContain('2026-08')
  })

  it('drops a malformed month from the URL', async () => {
    logIn()
    const fetchMock = api()
    const { router } = renderApp('/ai-usage?month=2026-13')

    await waitFor(() => expect(router.state.location.search).toBe(''))
    expect(await screen.findByText('$1.20 of $5.00 spent this month')).toBeInTheDocument()
    expect(fetchMock.mock.calls.map(([url]) => String(url)).filter((url) => url.includes('month='))).toEqual([])
  })

  it('says when a month has no AI calls', async () => {
    logIn()
    api({
      'GET /api/ai-usage': () =>
        jsonResponse(report({ month: '2026-09', totals: row({ calls: 0, cost_usd: 0 }), by_feature: [], by_user: [], by_day: [] })),
    })
    renderApp('/ai-usage?month=2026-09')

    expect(await screen.findByRole('heading', { name: 'No AI calls in September 2026' })).toBeInTheDocument()
    expect(screen.queryByRole('table')).not.toBeInTheDocument()
  })

  it('shows an error with a retry', async () => {
    logIn()
    let fail = true
    api({
      'GET /api/ai-usage': () => (fail ? jsonResponse({ message: 'Server error' }, 500) : jsonResponse(report())),
    })
    const { user } = renderApp('/ai-usage')

    // After the query's one retry (a second later).
    expect(await screen.findByRole('heading', { name: 'Could not load AI usage' }, { timeout: 3000 })).toBeInTheDocument()
    fail = false
    await user.click(screen.getByRole('button', { name: 'Retry' }))
    expect(await screen.findByText('$1.20 of $5.00 spent this month')).toBeInTheDocument()
  })

  it('sets a custom budget, turns AI off with 0, and goes back to the default', async () => {
    logIn()
    const sent: unknown[] = []
    // The server's budget, so the refetch after saving sees the change too.
    let saved = budget()
    api({
      'GET /api/ai-usage': () => jsonResponse(report({ budget: saved })),
      'PUT /api/ai-budget': (init) => {
        const body = sentBody(init) as { monthly_budget_usd: number | null }
        sent.push(body)
        const amount = body.monthly_budget_usd
        saved = budget(
          amount === null ? {} : { limit_usd: amount, is_default: false, own_limit_usd: amount, remaining_usd: Math.max(0, amount - 1.2) },
        )
        return jsonResponse({ data: saved })
      },
    })
    const { user } = renderApp('/ai-usage')

    // Custom: uncheck the default, type an amount.
    await user.click(await screen.findByRole('button', { name: 'Edit budget' }))
    let dialog = await screen.findByRole('dialog', { name: 'Monthly AI budget' })
    await user.click(within(dialog).getByRole('checkbox', { name: "Use the Free plan's budget ($5.00)" }))
    await user.type(within(dialog).getByLabelText('Budget per month (USD)'), '2.5')
    await user.click(within(dialog).getByRole('button', { name: 'Save' }))
    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument())
    expect(await screen.findByText('$1.20 of $2.50 spent this month')).toBeInTheDocument()
    expect(screen.getByText('Custom')).toBeInTheDocument()

    // 0: AI off.
    await user.click(screen.getByRole('button', { name: 'Edit budget' }))
    dialog = await screen.findByRole('dialog', { name: 'Monthly AI budget' })
    const amount = within(dialog).getByLabelText('Budget per month (USD)')
    expect(amount).toHaveValue('2.50')
    await user.clear(amount)
    await user.type(amount, '0')
    await user.click(within(dialog).getByRole('button', { name: 'Save' }))
    expect(await screen.findByText('AI is off for this organization', { selector: 'p' })).toBeInTheDocument()

    // Back to the default.
    await user.click(screen.getByRole('button', { name: 'Edit budget' }))
    dialog = await screen.findByRole('dialog', { name: 'Monthly AI budget' })
    await user.click(within(dialog).getByRole('checkbox', { name: "Use the Free plan's budget ($5.00)" }))
    expect(within(dialog).getByLabelText('Budget per month (USD)')).toBeDisabled()
    await user.click(within(dialog).getByRole('button', { name: 'Save' }))
    expect(await screen.findByText('$1.20 of $5.00 spent this month')).toBeInTheDocument()

    expect(sent).toEqual([{ monthly_budget_usd: 2.5 }, { monthly_budget_usd: 0 }, { monthly_budget_usd: null }])
  })

  it('checks the amount, and shows the API\'s 422 on the field', async () => {
    logIn()
    api({
      'PUT /api/ai-budget': () =>
        jsonResponse({ message: 'Invalid', errors: { monthly_budget_usd: ['Refused by the server.'] } }, 422),
    })
    const { user } = renderApp('/ai-usage')

    await user.click(await screen.findByRole('button', { name: 'Edit budget' }))
    const dialog = await screen.findByRole('dialog', { name: 'Monthly AI budget' })
    await user.click(within(dialog).getByRole('checkbox', { name: "Use the Free plan's budget ($5.00)" }))
    const amount = within(dialog).getByLabelText('Budget per month (USD)')

    await user.click(within(dialog).getByRole('button', { name: 'Save' }))
    expect(await within(dialog).findByText("Enter an amount, or use the plan's budget.")).toBeInTheDocument()

    await user.type(amount, '1.005')
    await user.click(within(dialog).getByRole('button', { name: 'Save' }))
    expect(await within(dialog).findByText('Use at most 2 decimals (cents).')).toBeInTheDocument()

    // Above the plan's budget: refused before asking the API.
    await user.clear(amount)
    await user.type(amount, '5.01')
    await user.click(within(dialog).getByRole('button', { name: 'Save' }))
    expect(await within(dialog).findByText('The Free plan allows at most $5.00 a month.')).toBeInTheDocument()

    // The API's own refusal lands on the field.
    await user.clear(amount)
    await user.type(amount, '4')
    await user.click(within(dialog).getByRole('button', { name: 'Save' }))
    expect(await within(dialog).findByText('Refused by the server.')).toBeInTheDocument()
    expect(amount).toHaveAttribute('aria-invalid', 'true')
  })

  it('shows AI credits in the budget, and allows up to the plan\'s budget', async () => {
    logIn()
    api({
      'GET /api/ai-usage': () =>
        jsonResponse(report({ budget: budget({ plan: 'Pro', default_limit_usd: 50, limit_usd: 60, credits_usd: 10, remaining_usd: 58.8 }) })),
    })
    const { user } = renderApp('/ai-usage')

    expect(await screen.findByText('$1.20 of $60.00 spent this month')).toBeInTheDocument()
    expect(screen.getByText(/Includes \$10\.00 of AI credits, through Oct 31, 2026\./)).toBeInTheDocument()
    expect(screen.getByText('Pro plan')).toBeInTheDocument()

    await user.click(screen.getByRole('button', { name: 'Edit budget' }))
    const dialog = await screen.findByRole('dialog', { name: 'Monthly AI budget' })
    expect(within(dialog).getByText(/At most \$50\.00 on the Pro plan/)).toBeInTheDocument()
  })

  it('gives focus back to Edit budget when the dialog closes', async () => {
    logIn()
    api()
    const { user } = renderApp('/ai-usage')

    const edit = await screen.findByRole('button', { name: 'Edit budget' })
    await user.click(edit)
    const dialog = await screen.findByRole('dialog', { name: 'Monthly AI budget' })
    await user.click(within(dialog).getByRole('button', { name: 'Cancel' }))

    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument())
    expect(edit).toHaveFocus()
  })

  it('has no Edit button without ai_budget.manage', async () => {
    logIn({ ...ada, current_organization: { ...acme, role: memberRole, permissions: ['ai_usage.view'] } })
    api({ 'GET /api/ai-usage': () => jsonResponse(report({ can: { manage_budget: false } })) })
    renderApp('/ai-usage')

    expect(await screen.findByText('$1.20 of $5.00 spent this month')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Edit budget' })).not.toBeInTheDocument()
  })

  it('shows the AI usage entry only with ai_usage.view, and no access without it', async () => {
    logIn({ ...ada, current_organization: { ...acme, role: memberRole, permissions: ['tasks.create', 'ai.use'] } })
    const fetchMock = api()
    renderApp('/ai-usage')

    expect(await screen.findByRole('heading', { name: "You don't have access to AI usage" })).toBeInTheDocument()
    expect(screen.queryByRole('link', { name: 'AI usage' })).not.toBeInTheDocument()
    expect(fetchMock.mock.calls.map(([url]) => String(url)).filter((url) => url.includes('/api/ai-usage'))).toEqual([])
  })

  it('shows the entry to roles with ai_usage.view', async () => {
    logIn()
    api()
    renderApp('/ai-usage')

    expect(await screen.findByRole('link', { name: 'AI usage' })).toBeInTheDocument()
  })
})
