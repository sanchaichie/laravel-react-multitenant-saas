import { useEffect, useState } from 'react'
import { CreditCardIcon, ReceiptTextIcon, SparklesIcon } from 'lucide-react'
import { Link, useSearchParams } from 'react-router'
import { toast } from 'sonner'
import { ErrorState } from '@/components/ErrorState'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardAction, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from '@/components/ui/card'
import { Progress } from '@/components/ui/progress'
import { Skeleton } from '@/components/ui/skeleton'
import { Spinner } from '@/components/ui/spinner'
import { useBilling, useBuyCredits, useOpenPortal, useStartCheckout } from '@/features/billing/api'
import { creditsBefore, paymentArrived, parseCheckoutReturn, rememberCredits } from '@/features/billing/checkout'
import { useCan } from '@/hooks/useCan'
import { ApiError } from '@/lib/api'
import { errorMessage } from '@/lib/apiErrors'
import { formatDate, formatLastDay, formatUsd } from '@/lib/format'
import { useCurrentOrganization } from '@/stores/auth'
import type { AiBudget, Billing, CreditPack } from '@/types/api'

// Stripe's webhook usually lands within seconds of the redirect back.
const POLL_INTERVAL_MS = 2000
const GIVE_UP_AFTER_MS = 30_000

export function BillingPage() {
  const organization = useCurrentOrganization()
  const canManage = useCan('billing.manage')
  const [params, setParams] = useSearchParams()
  // Read from the URL on every render (never copied into state), like a filter.
  const checkout = parseCheckoutReturn(params.get('checkout'))
  const waitingFor = checkout === 'success' || checkout === 'credits' ? checkout : null
  // Read once, when the page opens on the way back from Checkout (a lazy initial state:
  // the function runs on the first render only).
  const [creditsBeforeUsd] = useState(() => (organization ? creditsBefore(organization.id) : null))
  const [gaveUp, setGaveUp] = useState(false)

  const billing = useBilling({
    enabled: canManage,
    // Asked after each fetch: polling stops once the payment is in, or when we give up.
    pollEvery: (latest) => (waitingFor && !gaveUp && !paymentArrived(waitingFor, latest, creditsBeforeUsd) ? POLL_INTERVAL_MS : false),
  })
  const data = billing.data?.data
  const arrived = waitingFor !== null && paymentArrived(waitingFor, data, creditsBeforeUsd)

  // The flag is handled once: say what happened, then drop it from the URL (replace, so
  // Back doesn't bring it back). An effect, since it's a reaction to data, not a click.
  // The toast id keeps React's development double-run from showing it twice.
  useEffect(() => {
    if (checkout === 'canceled') {
      toast.info('Checkout canceled. Nothing was charged.', { id: 'checkout' })
      setParams({}, { replace: true })
    } else if (arrived) {
      toast.success(checkout === 'success' ? `You're on ${data?.plan.name ?? 'the new plan'}` : 'AI credits added', { id: 'checkout' })
      setParams({}, { replace: true })
    }
  }, [checkout, arrived, data?.plan.name, setParams])

  if (!organization) {
    return (
      <ErrorState
        title="No organization selected"
        description="Choose or create one from the switcher in the sidebar to see its billing."
      />
    )
  }

  // The role may have lost the permission since the page loaded: the API says 403.
  if (!canManage || (billing.error instanceof ApiError && billing.error.status === 403)) {
    return (
      <ErrorState
        title="You don't have access to billing"
        description="Ask an owner for a role with “Manage the plan, payment details and invoices”."
      />
    )
  }

  return (
    <div className="grid gap-6">
      <title>Billing · React SaaS</title>
      <div>
        <h1 className="text-2xl font-semibold">Billing</h1>
        <p className="text-sm text-muted-foreground">
          {organization.name}'s plan, AI credits and payment details. Payments are handled by Stripe.
        </p>
      </div>

      {waitingFor && !arrived && (
        <WaitingForStripe
          gaveUp={gaveUp}
          // The state setter itself: stable across renders, so the timer isn't restarted
          // by every poll's re-render.
          onGiveUp={setGaveUp}
          onRetry={() => {
            setGaveUp(false)
            void billing.refetch()
          }}
        />
      )}

      {billing.isError ? (
        <Card>
          <CardContent>
            <ErrorState title="Could not load billing" description={errorMessage(billing.error)}>
              <Button variant="outline" onClick={() => void billing.refetch()}>
                Retry
              </Button>
            </ErrorState>
          </CardContent>
        </Card>
      ) : !data ? (
        <div className="grid gap-4" aria-label="Loading billing">
          <Skeleton className="h-44 w-full" />
          <Skeleton className="h-28 w-full" />
          <Skeleton className="h-28 w-full" />
        </div>
      ) : (
        <>
          <PlanCard billing={data} />
          <BudgetCard budget={data.ai_budget} />
          <CreditsCard billing={data} organizationId={organization.id} />
        </>
      )}
    </div>
  )
}

interface WaitingProps {
  gaveUp: boolean
  onGiveUp: (gaveUp: true) => void
  onRetry: () => void
}

/** Shown while Stripe's webhook hasn't confirmed the payment. Mounted only while waiting. */
function WaitingForStripe({ gaveUp, onGiveUp, onRetry }: WaitingProps) {
  // A timer tied to this component (setTimeout in onMounted, cleared in onUnmounted).
  useEffect(() => {
    if (gaveUp) return
    const timer = setTimeout(() => onGiveUp(true), GIVE_UP_AFTER_MS)
    return () => clearTimeout(timer)
  }, [gaveUp, onGiveUp])

  if (gaveUp) {
    return (
      <Alert>
        <AlertTitle>Stripe hasn't confirmed the payment yet</AlertTitle>
        <AlertDescription>
          <p>It can take a few minutes. Nothing more to do: it shows here once Stripe tells us.</p>
          <Button variant="outline" size="sm" className="mt-2" onClick={onRetry}>
            Check again
          </Button>
        </AlertDescription>
      </Alert>
    )
  }

  return (
    <Alert role="status">
      <Spinner aria-hidden role="none" />
      <AlertTitle>Waiting for Stripe to confirm the payment…</AlertTitle>
      <AlertDescription>This usually takes a few seconds.</AlertDescription>
    </Alert>
  )
}

/**
 * Back from Stripe with the browser's Back button, the page may come from the
 * back/forward cache with its JavaScript as it was: the mutation still "succeeded",
 * which keeps its buttons disabled. `pageshow` with `persisted` says that happened.
 */
function useResetOnReturn(reset: () => void) {
  useEffect(() => {
    const onPageShow = (event: PageTransitionEvent) => {
      if (event.persisted) reset()
    }
    window.addEventListener('pageshow', onPageShow)
    return () => window.removeEventListener('pageshow', onPageShow)
  }, [reset])
}

function PlanCard({ billing }: { billing: Billing }) {
  const checkout = useStartCheckout()
  const portal = useOpenPortal()
  useResetOnReturn(checkout.reset)
  useResetOnReturn(portal.reset)
  const { plan, subscription, payment_method: card } = billing
  const upgrades = billing.can.subscribe ? billing.plans.filter((p) => p.is_paid) : []

  function onUpgrade(planKey: string) {
    // On success the browser leaves for Stripe; only a refusal comes back here.
    checkout.mutate(planKey, {
      onError: (error) => toast.error('Could not start the checkout', { description: errorMessage(error) }),
    })
  }

  function onManage() {
    portal.mutate(undefined, {
      onError: (error) => toast.error('Could not open Stripe', { description: errorMessage(error) }),
    })
  }

  // Each mutation stays pending while the browser loads Stripe's page.
  const leaving = checkout.isPending || checkout.isSuccess || portal.isPending || portal.isSuccess

  return (
    <Card>
      <CardHeader>
        <CardTitle>
          <h2 className="flex items-center gap-2">
            Plan
            <Badge variant={plan.is_paid ? 'default' : 'secondary'}>{plan.name}</Badge>
          </h2>
        </CardTitle>
        <CardDescription>
          {plan.display_price} · AI budget of {formatUsd(plan.ai_monthly_budget_usd)} a month
        </CardDescription>
        <CardAction>
          <Button variant="link" size="sm" asChild>
            <Link to="/billing/invoices">
              <ReceiptTextIcon aria-hidden />
              Invoices
            </Link>
          </Button>
        </CardAction>
      </CardHeader>
      <CardContent className="grid gap-3 text-sm">
        <SubscriptionStatusText billing={billing} />
        {card && (
          <p className="flex items-center gap-2 text-muted-foreground">
            <CreditCardIcon aria-hidden className="size-4" />
            {cardLabel(card.brand)}
            {card.last_four && ` ending in ${card.last_four}`}
          </p>
        )}
        {upgrades.map((paid) => (
          <p key={paid.key} className="text-muted-foreground">
            {paid.name}: {paid.display_price}, with an AI budget of {formatUsd(paid.ai_monthly_budget_usd)} a month.
          </p>
        ))}
        {subscription?.status === 'past_due' && (
          <Alert variant="destructive">
            <AlertTitle>The last payment failed</AlertTitle>
            <AlertDescription>
              Stripe is retrying the card, and the plan stays on meanwhile. Update the card in Manage billing.
            </AlertDescription>
          </Alert>
        )}
      </CardContent>
      {(upgrades.length > 0 || billing.can.open_portal) && (
        <CardFooter className="flex flex-wrap gap-2">
          {upgrades.map((paid) => (
            <Button key={paid.key} onClick={() => onUpgrade(paid.key)} disabled={leaving}>
              {checkout.isPending && checkout.variables === paid.key ? <Spinner /> : <SparklesIcon aria-hidden />}
              Upgrade to {paid.name}
            </Button>
          ))}
          {billing.can.open_portal && (
            <Button variant="outline" onClick={onManage} disabled={leaving}>
              {portal.isPending && <Spinner />}
              Manage billing
            </Button>
          )}
        </CardFooter>
      )}
    </Card>
  )
}

function SubscriptionStatusText({ billing }: { billing: Billing }) {
  const { plan, subscription } = billing
  if (!plan.is_paid || !subscription) {
    return <p>The organization is on the free plan.</p>
  }
  if (subscription.on_grace_period && subscription.ends_at) {
    return <p>Canceled: {plan.name} stays on until {formatDate(subscription.ends_at)}, then the organization goes back to Free.</p>
  }
  if (subscription.status === 'trialing') {
    return <p>On a trial of {plan.name}.</p>
  }
  return <p>{plan.name} renews automatically each month.</p>
}

const cardBrands: Record<string, string> = { visa: 'Visa', mastercard: 'Mastercard', amex: 'American Express', discover: 'Discover' }

function cardLabel(brand: string): string {
  return cardBrands[brand] ?? brand.charAt(0).toUpperCase() + brand.slice(1)
}

function BudgetCard({ budget }: { budget: AiBudget }) {
  const canSeeUsage = useCan('ai_usage.view')
  // Derived during render: nothing to keep in sync.
  const off = budget.limit_usd === 0
  const percent = budget.limit_usd > 0 ? Math.min(100, (budget.spent_usd / budget.limit_usd) * 100) : 100
  const label = off
    ? 'AI is off for this organization'
    : `${formatUsd(budget.spent_usd)} of ${formatUsd(budget.limit_usd)} spent this month`

  return (
    <Card>
      <CardHeader>
        <CardTitle>
          <h2>AI budget this month</h2>
        </CardTitle>
        <CardDescription>
          {off
            ? 'The budget is $0: nobody can start AI work until it is raised on the AI usage page.'
            : budget.credits_usd > 0
              ? `Includes ${formatUsd(budget.credits_usd)} of AI credits, through ${formatLastDay(budget.credits_expire_at)}.`
              : `${formatUsd(budget.remaining_usd)} left. Resets on the 1st (UTC).`}
        </CardDescription>
        {canSeeUsage && (
          <CardAction>
            <Button variant="link" size="sm" asChild>
              <Link to="/ai-usage">AI usage</Link>
            </Button>
          </CardAction>
        )}
      </CardHeader>
      <CardContent className="grid gap-2">
        <p className="text-sm font-medium">{label}</p>
        <Progress value={percent} aria-label={label} className="h-2" />
      </CardContent>
    </Card>
  )
}

function CreditsCard({ billing, organizationId }: { billing: Billing; organizationId: number }) {
  const buy = useBuyCredits()
  useResetOnReturn(buy.reset)

  function onBuy(pack: CreditPack) {
    // So the page can tell when the new credits have arrived, on the way back.
    rememberCredits(organizationId, billing.ai_budget.credits_usd)
    buy.mutate(pack.key, {
      onError: (error) => toast.error('Could not start the checkout', { description: errorMessage(error) }),
    })
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle>
          <h2>AI credits</h2>
        </CardTitle>
        <CardDescription>
          A one-time top-up on any plan, added to this month's AI budget. Credits last until the end of the month (UTC).
        </CardDescription>
      </CardHeader>
      <CardContent className="grid gap-3">
        {!billing.can.buy_credits && (
          <p className="text-sm text-muted-foreground">AI is turned off for this organization (budget $0), so credits can't be bought.</p>
        )}
        <ul className="grid gap-2">
          {billing.credit_packs.map((pack) => (
            <li key={pack.key} className="flex flex-wrap items-center justify-between gap-2 rounded-md border p-3">
              <div>
                <p className="text-sm font-medium">{pack.name}</p>
                <p className="text-sm text-muted-foreground">
                  {pack.available ? pack.display_price : 'Not available yet'}
                </p>
              </div>
              <Button
                variant="outline"
                onClick={() => onBuy(pack)}
                disabled={!billing.can.buy_credits || !pack.available || buy.isPending || buy.isSuccess}
                aria-label={`Buy ${pack.name}`}
              >
                {buy.isPending && buy.variables === pack.key && <Spinner />}
                Buy
              </Button>
            </li>
          ))}
        </ul>
      </CardContent>
    </Card>
  )
}
