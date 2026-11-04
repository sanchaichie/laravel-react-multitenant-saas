import { act, screen, within } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { leaveApp } from '@/lib/leaveApp'
import { makeAiBudget, makeBilling, proBilling } from '@/test/billing'
import { acme, ada, beta, jsonResponse, logIn, memberRole, mockApi, organizationList, renderApp, sentBody } from '@/test/utils'

// jsdom can't leave the page: record where the app would go instead.
vi.mock('@/lib/leaveApp', () => ({ leaveApp: vi.fn() }))

type Handler = (init: RequestInit, url: URL) => Response

function api(overrides: Record<string, Handler> = {}) {
  return mockApi({
    'GET /api/organizations': () => jsonResponse(organizationList([acme, beta])),
    'GET /api/billing': () => jsonResponse(makeBilling()),
    ...overrides,
  })
}

beforeEach(() => {
  sessionStorage.clear()
})

afterEach(() => {
  vi.useRealTimers()
  vi.mocked(leaveApp).mockReset()
})

describe('BillingPage', () => {
  it('shows the free plan, the AI budget and the credit packs', async () => {
    logIn()
    api()
    renderApp('/billing')

    const plan = await screen.findByRole('heading', { name: /Plan/ })
    expect(within(plan).getByText('Free')).toBeInTheDocument()
    expect(screen.getByText('The organization is on the free plan.')).toBeInTheDocument()
    expect(screen.getByText('Pro: $19 / month, with an AI budget of $50.00 a month.')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Upgrade to Pro' })).toBeEnabled()
    // Not a customer yet: nothing to manage.
    expect(screen.queryByRole('button', { name: 'Manage billing' })).not.toBeInTheDocument()
    expect(screen.getByRole('progressbar', { name: '$1.00 of $5.00 spent this month' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Buy $10 of AI credits' })).toBeEnabled()
    expect(screen.getByRole('link', { name: 'Invoices' })).toHaveAttribute('href', '/billing/invoices')
  })

  it('starts a checkout by plan key and goes to Stripe', async () => {
    logIn()
    const fetchMock = api({
      'POST /api/billing/checkout': () => jsonResponse({ data: { url: 'https://checkout.stripe.com/c/pay/cs_test_1' } }),
    })
    const { user } = renderApp('/billing')

    await user.click(await screen.findByRole('button', { name: 'Upgrade to Pro' }))

    await vi.waitFor(() => expect(leaveApp).toHaveBeenCalledWith('https://checkout.stripe.com/c/pay/cs_test_1'))
    const call = fetchMock.mock.calls.find(([url]) => String(url).endsWith('/api/billing/checkout'))
    expect(sentBody(call?.[1] ?? {})).toEqual({ plan: 'pro' })
    // Stays disabled while the browser loads Stripe.
    expect(screen.getByRole('button', { name: 'Upgrade to Pro' })).toBeDisabled()
  })

  it('shows why a checkout was refused', async () => {
    logIn()
    api({
      'POST /api/billing/checkout': () =>
        jsonResponse({ message: 'Billing is unavailable right now. Try again in a few minutes.', code: 'billing_unavailable' }, 503),
    })
    const { user } = renderApp('/billing')

    await user.click(await screen.findByRole('button', { name: 'Upgrade to Pro' }))

    expect(await screen.findByText('Billing is unavailable right now. Try again in a few minutes.')).toBeInTheDocument()
    expect(leaveApp).not.toHaveBeenCalled()
    expect(screen.getByRole('button', { name: 'Upgrade to Pro' })).toBeEnabled()
  })

  it('shows Pro with its card, and opens the Customer Portal', async () => {
    logIn()
    api({
      'GET /api/billing': () => jsonResponse(proBilling()),
      'POST /api/billing/portal': () => jsonResponse({ data: { url: 'https://billing.stripe.com/p/session/test_1' } }),
    })
    const { user } = renderApp('/billing')

    expect(await screen.findByText('Pro renews automatically each month.')).toBeInTheDocument()
    expect(screen.getByText('Visa ending in 4242')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Upgrade to Pro' })).not.toBeInTheDocument()

    await user.click(screen.getByRole('button', { name: 'Manage billing' }))
    await vi.waitFor(() => expect(leaveApp).toHaveBeenCalledWith('https://billing.stripe.com/p/session/test_1'))
  })

  it('says when a canceled plan ends', async () => {
    logIn()
    api({
      'GET /api/billing': () =>
        jsonResponse(proBilling({ subscription: { status: 'active', ends_at: '2026-11-30T12:00:00Z', on_grace_period: true } })),
    })
    renderApp('/billing')

    expect(
      await screen.findByText('Canceled: Pro stays on until Nov 30, 2026, then the organization goes back to Free.'),
    ).toBeInTheDocument()
  })

  it('warns about a failed payment', async () => {
    logIn()
    api({ 'GET /api/billing': () => jsonResponse(proBilling({ subscription: { status: 'past_due', ends_at: null, on_grace_period: false } })) })
    renderApp('/billing')

    expect(await screen.findByText('The last payment failed')).toBeInTheDocument()
  })

  it('waits for Stripe after a checkout, then shows the new plan and drops the flag', async () => {
    logIn()
    let calls = 0
    api({
      // The webhook lands between the second and third fetch.
      'GET /api/billing': () => jsonResponse(++calls < 3 ? makeBilling() : proBilling()),
    })
    const { router } = renderApp('/billing?checkout=success')

    expect(await screen.findByText('Waiting for Stripe to confirm the payment…')).toBeInTheDocument()
    expect(await screen.findByText("You're on Pro", {}, { timeout: 6000 })).toBeInTheDocument()
    expect(screen.queryByText('Waiting for Stripe to confirm the payment…')).not.toBeInTheDocument()
    expect(router.state.location.search).toBe('')
    expect(calls).toBe(3)
  }, 10_000)

  it('remembers the credits it had before leaving to buy more', async () => {
    logIn()
    const fetchMock = api({
      'GET /api/billing': () => jsonResponse(makeBilling({ ai_budget: makeAiBudget({ credits_usd: 10, limit_usd: 15 }) })),
      'POST /api/billing/credits/checkout': () => jsonResponse({ data: { url: 'https://checkout.stripe.com/c/pay/cs_test_2' } }),
    })
    const { user } = renderApp('/billing')

    await user.click(await screen.findByRole('button', { name: 'Buy $10 of AI credits' }))

    await vi.waitFor(() => expect(leaveApp).toHaveBeenCalledWith('https://checkout.stripe.com/c/pay/cs_test_2'))
    const call = fetchMock.mock.calls.find(([url]) => String(url).endsWith('/api/billing/credits/checkout'))
    expect(sentBody(call?.[1] ?? {})).toEqual({ pack: 'credits_10' })
    // So the page knows on the way back when the new ones have arrived.
    expect(sessionStorage.getItem(`billing:credits-before:${acme.id}`)).toBe('10')
  })

  it('treats credits above the saved amount as arrived', async () => {
    logIn()
    sessionStorage.setItem(`billing:credits-before:${acme.id}`, '10')
    let calls = 0
    api({
      'GET /api/billing': () => jsonResponse(makeBilling({ ai_budget: makeAiBudget({ credits_usd: ++calls < 2 ? 10 : 20 }) })),
    })
    const { router } = renderApp('/billing?checkout=credits')

    expect(await screen.findByText('Waiting for Stripe to confirm the payment…')).toBeInTheDocument()
    expect(await screen.findByText('AI credits added', {}, { timeout: 4000 })).toBeInTheDocument()
    expect(screen.getByText(/Includes \$20\.00 of AI credits, through Nov 30, 2026\./)).toBeInTheDocument()
    expect(router.state.location.search).toBe('')
  }, 8000)

  it('stops waiting after a while, and can check again', async () => {
    vi.useFakeTimers({ shouldAdvanceTime: true })
    logIn()
    let calls = 0
    api({ 'GET /api/billing': () => (calls++, jsonResponse(makeBilling())) })
    renderApp('/billing?checkout=success')

    expect(await screen.findByText('Waiting for Stripe to confirm the payment…')).toBeInTheDocument()
    await act(() => vi.advanceTimersByTimeAsync(31_000))
    expect(await screen.findByText("Stripe hasn't confirmed the payment yet")).toBeInTheDocument()

    // No more polling once it gave up.
    const after = calls
    await act(() => vi.advanceTimersByTimeAsync(10_000))
    expect(calls).toBe(after)

    await act(async () => screen.getByRole('button', { name: 'Check again' }).click())
    expect(await screen.findByText('Waiting for Stripe to confirm the payment…')).toBeInTheDocument()
    await vi.waitFor(() => expect(calls).toBeGreaterThan(after))
  })

  it('says a canceled checkout charged nothing', async () => {
    logIn()
    api()
    const { router } = renderApp('/billing?checkout=canceled')

    expect(await screen.findByText('Checkout canceled. Nothing was charged.')).toBeInTheDocument()
    expect(router.state.location.search).toBe('')
  })

  it('refuses credits while AI is off', async () => {
    logIn()
    api({
      'GET /api/billing': () =>
        jsonResponse(makeBilling({ ai_budget: makeAiBudget({ limit_usd: 0, own_limit_usd: 0, is_default: false }), can: { subscribe: true, open_portal: false, buy_credits: false } })),
    })
    renderApp('/billing')

    expect(await screen.findByText(/credits can't be bought/)).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Buy $10 of AI credits' })).toBeDisabled()
  })

  it('shows the no-access state without billing.manage, and asks nothing', async () => {
    logIn({ ...ada, current_organization: { ...acme, role: memberRole, permissions: ['tasks.create'] } })
    const fetchMock = api()
    renderApp('/billing')

    expect(await screen.findByRole('heading', { name: "You don't have access to billing" })).toBeInTheDocument()
    expect(fetchMock.mock.calls.some(([url]) => String(url).includes('/api/billing'))).toBe(false)
    // No sidebar entry either.
    expect(screen.queryByRole('link', { name: 'Billing' })).not.toBeInTheDocument()
  })

  it('shows the sidebar entry to owners, active on the invoices page too', async () => {
    logIn()
    api({ 'GET /api/billing/invoices': () => jsonResponse({ data: [], meta: { next_cursor: null } }) })
    renderApp('/billing/invoices')

    // The sidebar's entry (the page also has a "Billing" back link).
    const sidebar = await screen.findByRole('link', { name: 'Billing', current: 'page' })
    expect(sidebar).toHaveAttribute('data-active', 'true')
  })

  it('shows an error with Retry', async () => {
    logIn()
    let fail = true
    api({
      'GET /api/billing': () => (fail ? jsonResponse({ message: 'Server Error' }, 500) : jsonResponse(makeBilling())),
    })
    const { user } = renderApp('/billing')

    expect(await screen.findByRole('heading', { name: 'Could not load billing' }, { timeout: 3000 })).toBeInTheDocument()
    fail = false
    await user.click(screen.getByRole('button', { name: 'Retry' }))
    expect(await screen.findByText('The organization is on the free plan.')).toBeInTheDocument()
  })
})

describe('BillingPage, back from Stripe', () => {
  it('doesn\'t wait for credits it has nothing to compare with', async () => {
    logIn()
    api({ 'GET /api/billing': () => jsonResponse(makeBilling({ ai_budget: makeAiBudget({ credits_usd: 10 }) })) })
    const { router } = renderApp('/billing?checkout=credits')

    expect(await screen.findByText(/Includes \$10\.00 of AI credits/)).toBeInTheDocument()
    expect(screen.queryByText('Waiting for Stripe to confirm the payment…')).not.toBeInTheDocument()
    await vi.waitFor(() => expect(router.state.location.search).toBe(''))
  })

  it('enables the buttons again when the browser comes back from its back/forward cache', async () => {
    logIn()
    api({ 'POST /api/billing/checkout': () => jsonResponse({ data: { url: 'https://checkout.stripe.com/c/pay/cs_test_1' } }) })
    const { user } = renderApp('/billing')

    await user.click(await screen.findByRole('button', { name: 'Upgrade to Pro' }))
    await vi.waitFor(() => expect(leaveApp).toHaveBeenCalled())
    expect(screen.getByRole('button', { name: 'Upgrade to Pro' })).toBeDisabled()

    act(() => {
      window.dispatchEvent(new PageTransitionEvent('pageshow', { persisted: true }))
    })
    await vi.waitFor(() => expect(screen.getByRole('button', { name: 'Upgrade to Pro' })).toBeEnabled())
  })
})
