import type { ReactNode } from 'react'
import { Badge } from '@/components/ui/badge'
import { Card, CardAction, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Progress } from '@/components/ui/progress'
import { formatLastDay, formatUsd } from '@/lib/format'
import type { AiBudget } from '@/types/api'

/**
 * This month's AI budget as a meter (the AI usage page, and the platform admin's
 * organization page). editButton: passed in like a slot, so the page keeps the
 * dialog's state; left out where the budget can't be changed.
 */
export function BudgetCard({ budget, editButton }: { budget: AiBudget; editButton?: ReactNode }) {
  // Derived during render (computed in Vue): nothing to keep in sync.
  const off = budget.limit_usd === 0
  const usedUp = !off && budget.remaining_usd <= 0
  const percent = budget.limit_usd > 0 ? Math.min(100, (budget.spent_usd / budget.limit_usd) * 100) : 100
  const label = off
    ? 'AI is off for this organization'
    : `${formatUsd(budget.spent_usd)} of ${formatUsd(budget.limit_usd)} spent this month`

  return (
    <Card>
      <CardHeader>
        <CardTitle>
          <h2 className="flex items-center gap-2">
            Monthly budget
            <Badge variant="secondary">{budget.is_default ? `${budget.plan} plan` : 'Custom'}</Badge>
          </h2>
        </CardTitle>
        <CardDescription>
          {off
            ? 'The budget is $0: nobody can start AI work until it is raised.'
            : usedUp
              ? 'Used up: AI work is paused until the 1st (UTC), or until the budget is raised.'
              : `${formatUsd(budget.remaining_usd)} left. Resets on the 1st (UTC).`}
          {budget.credits_usd > 0 &&
            ` Includes ${formatUsd(budget.credits_usd)} of AI credits, through ${formatLastDay(budget.credits_expire_at)}.`}
        </CardDescription>
        {editButton && <CardAction>{editButton}</CardAction>}
      </CardHeader>
      <CardContent className="grid gap-2">
        <p className="text-sm font-medium">{label}</p>
        <Progress value={percent} aria-label={label} className="h-2" />
      </CardContent>
    </Card>
  )
}
