import type { AiBudget, Billing, BillingPlan, Invoice } from '@/types/api'

export const freePlan: BillingPlan = { key: 'free', name: 'Free', display_price: '$0 / month', ai_monthly_budget_usd: 5, is_paid: false }
export const proPlan: BillingPlan = { key: 'pro', name: 'Pro', display_price: '$19 / month', ai_monthly_budget_usd: 50, is_paid: true }

export function makeAiBudget(overrides: Partial<AiBudget> = {}): AiBudget {
  return {
    limit_usd: 5,
    is_default: true,
    own_limit_usd: null,
    default_limit_usd: 5,
    plan: 'Free',
    credits_usd: 0,
    credits_expire_at: '2026-12-01T00:00:00Z',
    spent_usd: 1,
    remaining_usd: 4,
    ...overrides,
  }
}

/** GET /api/billing for an organization on Free that never paid, unless overridden. */
export function makeBilling(overrides: Partial<Billing> = {}): { data: Billing } {
  return {
    data: {
      plan: freePlan,
      subscription: null,
      payment_method: null,
      has_billing_account: false,
      plans: [freePlan, proPlan],
      credit_packs: [{ key: 'credits_10', name: '$10 of AI credits', display_price: '$10', amount_usd: 10, available: true }],
      ai_budget: makeAiBudget(),
      can: { subscribe: true, open_portal: false, buy_credits: true },
      ...overrides,
    },
  }
}

/** Billing on Pro, with a card, renewing. */
export function proBilling(overrides: Partial<Billing> = {}): { data: Billing } {
  return makeBilling({
    plan: proPlan,
    subscription: { status: 'active', ends_at: null, on_grace_period: false },
    payment_method: { brand: 'visa', last_four: '4242' },
    has_billing_account: true,
    ai_budget: makeAiBudget({ limit_usd: 50, default_limit_usd: 50, plan: 'Pro', remaining_usd: 49 }),
    can: { subscribe: false, open_portal: true, buy_credits: true },
    ...overrides,
  })
}

export function makeInvoice(overrides: Partial<Invoice> = {}): Invoice {
  return {
    id: 'in_1',
    number: 'ACME-0001',
    status: 'paid',
    total: 1900,
    currency: 'usd',
    description: 'Pro',
    created_at: '2026-10-01T12:00:00Z',
    hosted_url: 'https://invoice.stripe.com/i/acct_1/test_1',
    pdf_url: 'https://pay.stripe.com/invoice/acct_1/test_1/pdf',
    ...overrides,
  }
}
