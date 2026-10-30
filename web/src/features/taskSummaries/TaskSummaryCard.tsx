import { useEffect, useState } from 'react'
import { CheckIcon, PencilIcon, RefreshCwIcon, SparklesIcon, XIcon } from 'lucide-react'
import { toast } from 'sonner'
import { Alert, AlertDescription } from '@/components/ui/alert'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardAction, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Skeleton } from '@/components/ui/skeleton'
import { Spinner } from '@/components/ui/spinner'
import { ApiError } from '@/lib/api'
import { errorMessage } from '@/lib/apiErrors'
import { formatDate } from '@/lib/format'
import type { Task, TaskSummary } from '@/types/api'
import { useRequestTaskSummary, useReviewTaskSummary, useTaskSummary } from './api'
import { TaskSummaryEditor } from './TaskSummaryEditor'

/** How long the card keeps asking before it says "check back soon". */
const GIVE_UP_AFTER_MS = 60_000

/**
 * The task's AI summary. One card, a few states, decided by the latest summary's
 * status (like a v-if / v-else-if chain): none, pending, draft, approved, rejected
 * or failed. The approved summary stays visible under a newer one until that's approved.
 */
export function TaskSummaryCard({ task }: { task: Task }) {
  // The pending summary we stopped waiting for: state, since it changes what renders.
  const [gaveUpOn, setGaveUpOn] = useState<number | null>(null)
  const query = useTaskSummary(task.id, { gaveUpOn })
  const request = useRequestTaskSummary()

  function onRequest() {
    request.mutate(task.id, {
      onError: (error) =>
        toast.error('Could not request a summary', {
          description:
            error instanceof ApiError && error.status === 429
              ? 'Too many AI requests for this organization, try again in a minute.'
              : errorMessage(error),
        }),
    })
  }

  const latest = query.data?.data ?? null
  const approved = query.data?.approved ?? null
  // Not while one is being generated, unless the server says that one is stuck.
  const canRequest = (query.data?.can.regenerate ?? false) && (latest?.status !== 'pending' || latest.stuck)

  return (
    <Card>
      <CardHeader>
        <CardTitle>
          <h2 className="flex items-center gap-2">
            <SparklesIcon aria-hidden className="size-4" />
            AI summary
          </h2>
        </CardTitle>
        {canRequest && (
          <CardAction>
            <Button variant="outline" size="sm" onClick={onRequest} disabled={request.isPending}>
              {request.isPending ? <Spinner /> : <RefreshCwIcon aria-hidden />}
              {latest ? 'Regenerate' : 'Generate summary'}
            </Button>
          </CardAction>
        )}
      </CardHeader>
      <CardContent className="grid gap-4">
        {query.isPending ? (
          <div className="grid gap-2" aria-label="Loading summary">
            <Skeleton className="h-4 w-full" />
            <Skeleton className="h-4 w-2/3" />
          </div>
        ) : query.isError ? (
          <div className="flex flex-wrap items-center gap-3 text-sm">
            <p className="text-muted-foreground">Could not load the summary. {errorMessage(query.error)}</p>
            <Button variant="outline" size="sm" onClick={() => void query.refetch()}>
              Retry
            </Button>
          </div>
        ) : (
          <>
            <LatestSummary latest={latest} gaveUp={latest !== null && gaveUpOn === latest.id} onGiveUp={setGaveUpOn} />
            {approved && approved.id !== latest?.id && (
              <section aria-label="Approved summary" className="grid gap-2 border-t pt-4">
                <h3 className="text-sm font-medium">Approved summary</h3>
                <SummaryText summary={approved} />
                <ApprovedBy summary={approved} />
                <StaleNote summary={approved} />
              </section>
            )}
          </>
        )}
      </CardContent>
    </Card>
  )
}

interface LatestSummaryProps {
  latest: TaskSummary | null
  gaveUp: boolean
  onGiveUp: (summaryId: number) => void
}

function LatestSummary({ latest, gaveUp, onGiveUp }: LatestSummaryProps) {
  if (latest === null) {
    return <p className="text-sm text-muted-foreground">No summary yet.</p>
  }

  switch (latest.status) {
    case 'pending':
      // key: a newer pending summary starts its own wait.
      return <PendingSummary key={latest.id} summaryId={latest.id} gaveUp={gaveUp} onGiveUp={onGiveUp} />
    case 'draft':
      // key: a different draft opens in view mode, not in the old one's editor.
      return <DraftSummary key={latest.id} summary={latest} />
    case 'approved':
      return (
        <div className="grid gap-2">
          <SummaryText summary={latest} />
          <ApprovedBy summary={latest} />
          <StaleNote summary={latest} />
        </div>
      )
    case 'rejected':
      return <p className="text-sm text-muted-foreground">The last draft was rejected.</p>
    case 'failed':
      return (
        <Alert variant="destructive">
          <AlertDescription>{latest.error ?? 'The summary could not be generated.'}</AlertDescription>
        </Alert>
      )
  }
}

interface PendingSummaryProps {
  summaryId: number
  gaveUp: boolean
  onGiveUp: (summaryId: number) => void
}

/** Polling runs in useTaskSummary; this only counts down to "check back soon". */
function PendingSummary({ summaryId, gaveUp, onGiveUp }: PendingSummaryProps) {
  // A timer is a side effect of being on screen (onMounted + onUnmounted in Vue):
  // the cleanup clears it if the card goes away first.
  useEffect(() => {
    const timer = setTimeout(() => onGiveUp(summaryId), GIVE_UP_AFTER_MS)
    return () => clearTimeout(timer)
  }, [summaryId, onGiveUp])

  return (
    <div className="grid gap-2">
      {!gaveUp && (
        <>
          <Skeleton className="h-4 w-full" />
          <Skeleton className="h-4 w-2/3" />
        </>
      )}
      {/* aria-live: screen readers announce it when it changes. */}
      <p className="text-sm text-muted-foreground" aria-live="polite">
        {gaveUp ? 'Still working, check back soon.' : 'Generating…'}
      </p>
    </div>
  )
}

function DraftSummary({ summary }: { summary: TaskSummary }) {
  const [editing, setEditing] = useState(false)
  const review = useReviewTaskSummary()

  function onReview(approve: boolean) {
    review.mutate(
      { taskId: summary.task_id, summaryId: summary.id, approve },
      {
        onSuccess: () => toast.success(approve ? 'Summary approved' : 'Summary rejected'),
        onError: (error) =>
          toast.error(approve ? 'Could not approve the summary' : 'Could not reject the summary', {
            description: errorMessage(error),
          }),
      },
    )
  }

  return (
    <div className="grid gap-3">
      <div>
        <Badge variant="secondary">AI draft</Badge>
      </div>
      {editing ? (
        <TaskSummaryEditor summary={summary} onDone={() => setEditing(false)} onCancel={() => setEditing(false)} />
      ) : (
        <>
          <SummaryText summary={summary} />
          <StaleNote summary={summary} />
          {summary.can.review && (
            <div className="flex flex-wrap gap-2">
              <Button size="sm" onClick={() => onReview(true)} disabled={review.isPending}>
                <CheckIcon aria-hidden />
                Approve
              </Button>
              <Button size="sm" variant="outline" onClick={() => setEditing(true)} disabled={review.isPending}>
                <PencilIcon aria-hidden />
                Edit
              </Button>
              <Button size="sm" variant="outline" onClick={() => onReview(false)} disabled={review.isPending}>
                <XIcon aria-hidden />
                Reject
              </Button>
            </div>
          )}
        </>
      )}
    </div>
  )
}

/** The summary and its key points, as plain text (never HTML). */
function SummaryText({ summary }: { summary: TaskSummary }) {
  return (
    <div className="grid gap-2 text-sm">
      <p className="break-words whitespace-pre-wrap">{summary.summary}</p>
      {summary.key_points.length > 0 && (
        <ul className="list-disc space-y-1 pl-5">
          {summary.key_points.map((point, index) => (
            // Points can repeat and never reorder here: the index is a stable key.
            <li key={index} className="break-words">
              {point}
            </li>
          ))}
        </ul>
      )}
    </div>
  )
}

function ApprovedBy({ summary }: { summary: TaskSummary }) {
  if (!summary.reviewed_at) return null
  return (
    <p className="text-xs text-muted-foreground">
      Approved by {summary.reviewed_by?.name ?? 'a deleted user'} on {formatDate(summary.reviewed_at)}
    </p>
  )
}

function StaleNote({ summary }: { summary: TaskSummary }) {
  if (!summary.stale) return null
  return <p className="text-xs text-muted-foreground">The task changed since this summary was written.</p>
}
