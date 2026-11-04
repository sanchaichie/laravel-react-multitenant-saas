import type { Billing } from '@/types/api'

/**
 * Where Stripe Checkout sends the browser back: /billing?checkout=success (a plan),
 * credits (a credit pack) or canceled. Anything else is ignored.
 */
export type CheckoutReturn = 'success' | 'credits' | 'canceled'

export function parseCheckoutReturn(value: string | null): CheckoutReturn | null {
  return value === 'success' || value === 'credits' || value === 'canceled' ? value : null
}

// The organization's credits before leaving for Checkout, so the page knows when the
// new ones have arrived. sessionStorage: this tab only, gone when it closes.
const creditsKey = (organizationId: number) => `billing:credits-before:${organizationId}`

export function rememberCredits(organizationId: number, creditsUsd: number) {
  sessionStorage.setItem(creditsKey(organizationId), String(creditsUsd))
}

/** null: not remembered (e.g. Stripe's return link opened in another tab). */
export function creditsBefore(organizationId: number): number | null {
  const stored = sessionStorage.getItem(creditsKey(organizationId))
  const value = stored === null ? NaN : Number(stored)
  return Number.isFinite(value) ? value : null
}

/**
 * Whether Stripe's webhook has recorded what the person just paid for. Without the
 * credits from before, there's nothing to compare with: don't wait (the budget card
 * shows the credits once they're in).
 */
export function paymentArrived(checkout: 'success' | 'credits', billing: Billing | undefined, creditsBeforeUsd: number | null): boolean {
  if (!billing) return false
  if (checkout === 'success') return billing.plan.is_paid
  return creditsBeforeUsd === null || billing.ai_budget.credits_usd > creditsBeforeUsd
}
