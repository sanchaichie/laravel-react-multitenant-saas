import { type ReactNode, useId, useState } from 'react'
import { PencilIcon, SparklesIcon } from 'lucide-react'
import { Navigate, useSearchParams } from 'react-router'
import { ErrorState } from '@/components/ErrorState'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Skeleton } from '@/components/ui/skeleton'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { useAiUsage } from '@/features/aiUsage/api'
import { BudgetCard } from '@/features/aiUsage/BudgetCard'
import { BudgetDialog } from '@/features/aiUsage/BudgetDialog'
import { featureLabel, parseMonth } from '@/features/aiUsage/labels'
import { useCan } from '@/hooks/useCan'
import { ApiError } from '@/lib/api'
import { errorMessage } from '@/lib/apiErrors'
import { formatDay, formatMonth, formatUsd } from '@/lib/format'
import { useCurrentOrganization } from '@/stores/auth'
import type { AiUsageReport, AiUsageRow } from '@/types/api'

const numberFormatter = new Intl.NumberFormat('en-US')
const formatNumber = (value: number) => numberFormatter.format(value)

export function AiUsagePage() {
  const organization = useCurrentOrganization()
  const canView = useCan('ai_usage.view')
  const [params, setParams] = useSearchParams()
  const rawMonth = params.get('month')
  // Parsed on every render, never copied into state ('' = this month).
  const month = parseMonth(rawMonth)
  const usage = useAiUsage(month, canView)
  const [editing, setEditing] = useState(false)

  if (!organization) {
    return (
      <ErrorState
        title="No organization selected"
        description="Choose or create one from the switcher in the sidebar to see its AI usage."
      />
    )
  }

  // Your role may have lost the permission since the page loaded: the API says 403.
  if (!canView || (usage.error instanceof ApiError && usage.error.status === 403)) {
    return (
      <ErrorState
        title="You don't have access to AI usage"
        description="Ask an owner for a role with “View AI usage and the budget”."
      />
    )
  }

  // A malformed month (an old or hand-edited link) is dropped from the URL.
  if (rawMonth !== null && month === '') {
    return <Navigate to="/ai-usage" replace />
  }

  function changeMonth(value: string) {
    // Replaces the history entry, like a filter. An emptied field means this month.
    setParams(value ? { month: value } : {}, { replace: true })
  }

  const report = usage.data?.data

  return (
    <div className="grid gap-6">
      <title>AI usage · React SaaS</title>
      <div className="flex flex-wrap items-end justify-between gap-4">
        <div>
          <h1 className="text-2xl font-semibold">AI usage</h1>
          <p className="text-sm text-muted-foreground">What AI cost {organization.name}, month by month (UTC).</p>
        </div>
        {/* The URL's month first: the report may still be the previous one (placeholder). */}
        <MonthPicker value={month || (usage.isPlaceholderData ? '' : (report?.month ?? ''))} onChange={changeMonth} />
      </div>

      {usage.isError ? (
        <Card>
          <CardContent>
            <ErrorState title="Could not load AI usage" description={errorMessage(usage.error)}>
              <Button variant="outline" onClick={() => void usage.refetch()}>
                Retry
              </Button>
            </ErrorState>
          </CardContent>
        </Card>
      ) : !report ? (
        <div className="grid gap-4" aria-label="Loading AI usage">
          <Skeleton className="h-28 w-full" />
          <Skeleton className="h-20 w-full" />
          <Skeleton className="h-48 w-full" />
        </div>
      ) : (
        // Dimmed while another month loads over the one shown (placeholderData).
        <div className="grid gap-6 transition-opacity data-[stale=true]:opacity-60" data-stale={usage.isPlaceholderData}>
          <BudgetCard
            budget={report.budget}
            editButton={
              report.can.manage_budget && (
                <BudgetDialog budget={report.budget} open={editing} onOpenChange={setEditing}>
                  <Button variant="outline" size="sm">
                    <PencilIcon aria-hidden />
                    Edit budget
                  </Button>
                </BudgetDialog>
              )
            }
          />
          {report.totals.calls === 0 ? (
            <Card>
              <CardContent>
                <div className="flex flex-col items-center gap-3 px-4 py-12 text-center">
                  <SparklesIcon className="size-8 text-muted-foreground" aria-hidden />
                  <h2 className="text-lg font-semibold">No AI calls in {formatMonth(report.month)}</h2>
                  <p className="max-w-sm text-sm text-muted-foreground">
                    Task summaries and other AI work will show here, with what they cost.
                  </p>
                </div>
              </CardContent>
            </Card>
          ) : (
            <UsageDetails report={report} />
          )}
        </div>
      )}
    </div>
  )
}

function MonthPicker({ value, onChange }: { value: string; onChange: (month: string) => void }) {
  const id = useId()
  return (
    <div className="grid gap-1.5">
      <Label htmlFor={id}>Month</Label>
      {/* A native month input: the browser draws the picker. Controlled by the URL.
          No max from the browser's clock: the API refuses a future month (422). */}
      <Input id={id} type="month" className="w-44" value={value} onChange={(event) => onChange(event.target.value)} />
    </div>
  )
}

function UsageDetails({ report }: { report: AiUsageReport }) {
  const { totals } = report
  return (
    <>
      <section aria-label="Totals" className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-5">
        <Stat label="Calls" value={formatNumber(totals.calls)} />
        <Stat label="Failed" value={formatNumber(totals.failed)} />
        <Stat label="Tokens in" value={formatNumber(totals.input_tokens)} />
        <Stat label="Tokens out" value={formatNumber(totals.output_tokens)} />
        <Stat label="Cost" value={formatUsd(totals.cost_usd)} />
      </section>

      <UsageTable
        title="By feature"
        first="Feature"
        rows={report.by_feature.map((row) => ({ key: row.feature, name: featureLabel(row.feature), row }))}
      />
      <UsageTable
        title="By person"
        first="Person"
        rows={report.by_user.map((row) => ({
          key: row.user?.id ?? 'none',
          name: row.user?.name ?? <span className="text-muted-foreground">No one (automatic, or a deleted account)</span>,
          row,
        }))}
      />
      <UsageTable
        title="By day"
        first="Day"
        rows={report.by_day.map((row) => ({ key: row.day, name: formatDay(row.day), row }))}
      />
    </>
  )
}

function Stat({ label, value }: { label: string; value: string }) {
  return (
    <Card className="gap-1 py-4">
      <CardContent className="grid gap-1 px-4">
        <p className="text-xs text-muted-foreground">{label}</p>
        <p className="text-xl font-semibold tabular-nums">{value}</p>
      </CardContent>
    </Card>
  )
}

interface UsageTableProps {
  title: string
  first: string
  rows: Array<{ key: string | number; name: ReactNode; row: AiUsageRow }>
}

function UsageTable({ title, first, rows }: UsageTableProps) {
  const headingId = useId()
  return (
    <Card>
      <CardHeader>
        <CardTitle>
          <h2 id={headingId}>{title}</h2>
        </CardTitle>
      </CardHeader>
      <CardContent>
        <Table aria-labelledby={headingId}>
          <TableHeader>
            <TableRow>
              <TableHead>{first}</TableHead>
              <TableHead className="text-right">Calls</TableHead>
              <TableHead className="text-right">Failed</TableHead>
              <TableHead className="hidden text-right sm:table-cell">Tokens in</TableHead>
              <TableHead className="hidden text-right sm:table-cell">Tokens out</TableHead>
              <TableHead className="text-right">Cost</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {rows.map(({ key, name, row }) => (
              <TableRow key={key}>
                <TableCell className="font-medium whitespace-normal">{name}</TableCell>
                <TableCell className="text-right tabular-nums">{formatNumber(row.calls)}</TableCell>
                <TableCell className="text-right tabular-nums">{formatNumber(row.failed)}</TableCell>
                <TableCell className="hidden text-right tabular-nums sm:table-cell">{formatNumber(row.input_tokens)}</TableCell>
                <TableCell className="hidden text-right tabular-nums sm:table-cell">{formatNumber(row.output_tokens)}</TableCell>
                <TableCell className="text-right tabular-nums">{formatUsd(row.cost_usd)}</TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </CardContent>
    </Card>
  )
}
