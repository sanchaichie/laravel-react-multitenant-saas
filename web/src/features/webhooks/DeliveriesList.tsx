import { useState } from 'react'
import { ChevronRightIcon, RotateCwIcon } from 'lucide-react'
import { toast } from 'sonner'
import { Button } from '@/components/ui/button'
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible'
import { Spinner } from '@/components/ui/spinner'
import { errorMessage } from '@/lib/apiErrors'
import { formatDateTime } from '@/lib/format'
import { cn } from '@/lib/utils'
import type { WebhookDelivery } from '@/types/api'
import { useResendDelivery } from './api'
import { eventLabel } from './events'
import { DeliveryStatusBadge } from './WebhookBadges'

interface DeliveriesListProps {
  webhookId: number
  deliveries: WebhookDelivery[]
  /** Resend is offered only while the webhook is on (the API refuses otherwise). */
  canResend: boolean
  onSent: () => void
}

export function DeliveriesList({ webhookId, deliveries, canResend, onSent }: DeliveriesListProps) {
  return (
    <ul className="divide-y rounded-md border">
      {deliveries.map((delivery) => (
        <DeliveryRow key={delivery.id} webhookId={webhookId} delivery={delivery} canResend={canResend} onSent={onSent} />
      ))}
    </ul>
  )
}

interface DeliveryRowProps {
  webhookId: number
  delivery: WebhookDelivery
  canResend: boolean
  onSent: () => void
}

/**
 * One delivery; it opens to show what was sent and what came back. Each row keeps
 * its own open state (a child component with its own ref), so the parent holds no
 * map of open ids, and a refetch keeps open rows open (same key, same component).
 */
function DeliveryRow({ webhookId, delivery, canResend, onSent }: DeliveryRowProps) {
  const [open, setOpen] = useState(false)
  const resend = useResendDelivery(webhookId)

  function onResend() {
    resend.mutate(delivery, {
      onSuccess: () => {
        toast.success('Sent again')
        onSent()
      },
      onError: (error) => toast.error('Could not resend', { description: errorMessage(error) }),
    })
  }

  return (
    <li>
      <Collapsible open={open} onOpenChange={setOpen}>
        <div className="flex flex-wrap items-center gap-x-3 gap-y-1 px-3 py-2 text-sm">
          <CollapsibleTrigger asChild>
            <Button variant="ghost" size="sm" className="-ml-2 min-w-0 justify-start gap-2 px-2">
              <ChevronRightIcon aria-hidden className={cn('transition-transform', open && 'rotate-90')} />
              <span className="font-medium">{eventLabel(delivery.event)}</span>
              <span className="text-muted-foreground">{formatDateTime(delivery.created_at)}</span>
            </Button>
          </CollapsibleTrigger>
          <DeliveryStatusBadge status={delivery.status} />
          <span className="text-muted-foreground">{summary(delivery)}</span>
          {canResend && delivery.status !== 'pending' && (
            <Button variant="outline" size="sm" className="ml-auto" disabled={resend.isPending} onClick={onResend}>
              {resend.isPending ? <Spinner /> : <RotateCwIcon aria-hidden />}
              Resend
            </Button>
          )}
        </div>
        <CollapsibleContent className="grid gap-3 border-t bg-muted/30 px-3 py-3 text-sm">
          <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1">
            <dt className="text-muted-foreground">Event id</dt>
            <dd className="font-mono text-xs break-all">{delivery.event_id}</dd>
            <dt className="text-muted-foreground">Attempts</dt>
            <dd>{delivery.attempts}</dd>
            {delivery.next_attempt_at && (
              <>
                <dt className="text-muted-foreground">Next attempt</dt>
                <dd>{formatDateTime(delivery.next_attempt_at)}</dd>
              </>
            )}
            {delivery.error && (
              <>
                <dt className="text-muted-foreground">Error</dt>
                <dd>{delivery.error}</dd>
              </>
            )}
          </dl>
          {/* Other people's data: rendered as text in <pre>, never as HTML. */}
          <Block title="Request body">{JSON.stringify(delivery.payload, null, 2)}</Block>
          {delivery.response_body !== null && (
            <Block title={`Response${delivery.response_status ? ` (${delivery.response_status})` : ''}`}>{delivery.response_body}</Block>
          )}
        </CollapsibleContent>
      </Collapsible>
    </li>
  )
}

function Block({ title, children }: { title: string; children: string }) {
  return (
    <div className="grid gap-1">
      <h3 className="text-xs font-medium text-muted-foreground">{title}</h3>
      <pre className="max-h-72 overflow-auto rounded-md border bg-background p-2 font-mono text-xs break-all whitespace-pre-wrap">
        {children}
      </pre>
    </div>
  )
}

/** "200 · 85 ms", "503 · attempt 1 failed, retrying", "Timed out after 10 seconds.", ... */
function summary(delivery: WebhookDelivery): string {
  const parts: string[] = []
  if (delivery.response_status !== null) parts.push(String(delivery.response_status))
  else if (delivery.error && delivery.status !== 'succeeded') parts.push(delivery.error)
  if (delivery.duration_ms !== null && delivery.status === 'succeeded') parts.push(`${delivery.duration_ms} ms`)
  if (delivery.status === 'pending') parts.push(delivery.attempts === 0 ? 'sending…' : `attempt ${delivery.attempts} failed, retrying`)
  return parts.join(' · ')
}
