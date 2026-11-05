import { useState } from 'react'
import { ArrowLeftIcon, KeyRoundIcon, PowerIcon, SendIcon, Trash2Icon } from 'lucide-react'
import { Link, Navigate, useNavigate, useParams } from 'react-router'
import { toast } from 'sonner'
import { ConfirmDialog } from '@/components/ConfirmDialog'
import { ErrorState } from '@/components/ErrorState'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Skeleton } from '@/components/ui/skeleton'
import { Spinner } from '@/components/ui/spinner'
import {
  useDeleteWebhook,
  useRollWebhookSecret,
  useSendTestWebhook,
  useUpdateWebhook,
  useWebhook,
  useWebhookDeliveries,
} from '@/features/webhooks/api'
import { DeliveriesList } from '@/features/webhooks/DeliveriesList'
import { SecretDialog } from '@/features/webhooks/SecretDialog'
import { WebhookStatusBadge } from '@/features/webhooks/WebhookBadges'
import { WebhookForm } from '@/features/webhooks/WebhookForm'
import { useCan } from '@/hooks/useCan'
import { ApiError } from '@/lib/api'
import { errorMessage } from '@/lib/apiErrors'
import { formatDateTime } from '@/lib/format'
import { useAuth, useCurrentOrganization } from '@/stores/auth'
import type { WebhookEndpoint } from '@/types/api'

/** A webhook id as the API accepts it (up to 18 digits); anything else is "not found" without a request. */
function parseId(value: string | undefined): number | null {
  return value && /^[1-9][0-9]{0,17}$/.test(value) ? Number(value) : null
}

export function WebhookDetailPage() {
  const webhookId = parseId(useParams().id)
  const organizationId = useCurrentOrganization()?.id
  const canManage = useCan('webhooks.manage')
  // The webhook belongs to the organization this page opened in: after a switch, go
  // back to the list (read once, on mount, like the task page).
  const [openedIn] = useState(organizationId)
  const switched = organizationId !== openedIn
  const webhook = useWebhook(switched || !canManage ? null : webhookId)

  if (switched) {
    return <Navigate to="/webhooks" replace />
  }

  if (!canManage || (webhook.error instanceof ApiError && webhook.error.status === 403)) {
    return <ErrorState title="You don't have access to webhooks" description="Ask an owner for a role with “Manage webhooks”." />
  }

  if (webhookId === null || (webhook.error instanceof ApiError && webhook.error.status === 404)) {
    return (
      <>
        <title>Webhook not found · React SaaS</title>
        <ErrorState title="Webhook not found" description="It may have been deleted, or it belongs to another organization.">
          <Button variant="outline" asChild>
            <Link to="/webhooks">Back to webhooks</Link>
          </Button>
        </ErrorState>
      </>
    )
  }

  if (webhook.isPending) {
    return (
      <div className="grid gap-4" aria-label="Loading webhook">
        <Skeleton className="h-8 w-2/3" />
        <Skeleton className="h-60 w-full" />
      </div>
    )
  }

  if (webhook.isError) {
    return (
      <ErrorState title="Could not load the webhook" description={errorMessage(webhook.error)}>
        <Button variant="outline" onClick={() => void webhook.refetch()}>
          Retry
        </Button>
      </ErrorState>
    )
  }

  // key: a fresh component (and form) per webhook, if the id in the URL changes.
  return <WebhookDetails key={webhook.data.data.id} webhook={webhook.data.data} />
}

const POLL_FOR_MS = 60_000

function WebhookDetails({ webhook }: { webhook: WebhookEndpoint }) {
  const navigate = useNavigate()
  const supportView = useAuth((s) => s.user?.impersonated_by != null)
  const update = useUpdateWebhook(webhook.id)
  const remove = useDeleteWebhook()
  const roll = useRollWebhookSecret(webhook.id)
  const test = useSendTestWebhook(webhook.id)
  const [secret, setSecret] = useState<string | null>(null)
  // Until when the delivery list may poll (this browser's clock): a minute after the
  // page opens or something is sent, so a stopped queue doesn't poll forever.
  const [pollUntil, setPollUntil] = useState(() => Date.now() + POLL_FOR_MS)
  const keepPolling = () => setPollUntil(Date.now() + POLL_FOR_MS)

  function setEnabled(enabled: boolean) {
    update.mutate(
      { enabled },
      {
        onSuccess: () => toast.success(enabled ? 'Webhook turned on' : 'Webhook turned off'),
        onError: (error) => toast.error('Could not change the webhook', { description: errorMessage(error) }),
      },
    )
  }

  function onRoll() {
    roll.mutate(undefined, {
      onSuccess: (answer) => setSecret(answer.meta.secret),
      onError: (error) => toast.error('Could not roll the secret', { description: errorMessage(error) }),
    })
  }

  function onTest() {
    test.mutate(undefined, {
      onSuccess: () => {
        toast.success('Test event sent')
        keepPolling()
      },
      onError: (error) => toast.error('Could not send a test event', { description: errorMessage(error) }),
    })
  }

  function onDelete() {
    remove.mutate(webhook, {
      onSuccess: () => {
        toast.success('Webhook deleted')
        void navigate('/webhooks', { replace: true })
      },
      onError: (error) => toast.error('Could not delete the webhook', { description: errorMessage(error) }),
    })
  }

  return (
    <div className="grid gap-6">
      <title>{`${webhook.host} · Webhooks · React SaaS`}</title>
      <div className="grid gap-2">
        <Button variant="link" size="sm" className="justify-self-start px-0" asChild>
          <Link to="/webhooks">
            <ArrowLeftIcon aria-hidden />
            Webhooks
          </Link>
        </Button>
        <div className="flex flex-wrap items-center gap-3">
          <h1 className="text-2xl font-semibold break-all">{webhook.host}</h1>
          <WebhookStatusBadge webhook={webhook} />
        </div>
        {webhook.description && <p className="text-sm text-muted-foreground">{webhook.description}</p>}
      </div>

      {webhook.disabled_reason === 'failing' && (
        <Alert variant="destructive">
          <AlertTitle>Turned off because deliveries kept failing</AlertTitle>
          <AlertDescription>
            Fix the receiver (the failed deliveries below show what it answered), then turn the webhook back on. Events that
            happened while it was off weren't sent.
          </AlertDescription>
        </Alert>
      )}

      <Card>
        <CardHeader>
          <CardTitle>Settings</CardTitle>
          <CardDescription>Added {formatDateTime(webhook.created_at)}.</CardDescription>
        </CardHeader>
        <CardContent className="grid gap-6">
          {webhook.url_hidden ? (
            <p className="text-sm text-muted-foreground">The URL is hidden in the support view.</p>
          ) : (
            <WebhookForm webhook={webhook} />
          )}
          <div className="flex flex-wrap gap-2 border-t pt-4">
            <Button variant="outline" disabled={!webhook.enabled || test.isPending} onClick={onTest}>
              {test.isPending ? <Spinner /> : <SendIcon aria-hidden />}
              Send test event
            </Button>
            <Button variant="outline" disabled={update.isPending} onClick={() => setEnabled(!webhook.enabled)}>
              <PowerIcon aria-hidden />
              {webhook.enabled ? 'Turn off' : 'Turn on'}
            </Button>
            <ConfirmDialog
              title="Roll the signing secret?"
              description="The current secret stops working at once: every delivery from now on is signed with the new one. Update your receiver right after."
              confirmLabel="Roll secret"
              onConfirm={onRoll}
            >
              <Button variant="outline" disabled={roll.isPending}>
                {roll.isPending ? <Spinner /> : <KeyRoundIcon aria-hidden />}
                Roll secret
              </Button>
            </ConfirmDialog>
            <ConfirmDialog
              title="Delete this webhook?"
              description={`Nothing more is sent to ${webhook.host}, and its delivery log is deleted with it.`}
              confirmLabel="Delete webhook"
              onConfirm={onDelete}
            >
              <Button variant="outline" className="text-destructive" disabled={remove.isPending}>
                <Trash2Icon aria-hidden />
                Delete
              </Button>
            </ConfirmDialog>
          </div>
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>Deliveries</CardTitle>
          <CardDescription>
            What was sent and what came back, for 30 days. A failed delivery is tried up to 6 times over about 10 hours.
          </CardDescription>
        </CardHeader>
        <CardContent>
          {supportView ? (
            <p className="text-sm text-muted-foreground">Deliveries are hidden in the support view: they hold task content.</p>
          ) : (
            <Deliveries webhook={webhook} pollUntil={pollUntil} onSent={keepPolling} />
          )}
        </CardContent>
      </Card>

      <SecretDialog secret={secret} onClose={() => setSecret(null)} />
    </div>
  )
}

function Deliveries({ webhook, pollUntil, onSent }: { webhook: WebhookEndpoint; pollUntil: number; onSent: () => void }) {
  const deliveries = useWebhookDeliveries(webhook.id, { pollUntil })

  if (deliveries.isPending) {
    return (
      <div className="grid gap-2" aria-label="Loading deliveries">
        <Skeleton className="h-9 w-full" />
        <Skeleton className="h-9 w-full" />
      </div>
    )
  }

  if (deliveries.isError) {
    return (
      <ErrorState title="Could not load the deliveries" description={errorMessage(deliveries.error)}>
        <Button variant="outline" onClick={() => void deliveries.refetch()}>
          Retry
        </Button>
      </ErrorState>
    )
  }

  const rows = deliveries.data.pages.flatMap((page) => page.data)

  if (rows.length === 0) {
    return (
      <p className="py-6 text-center text-sm text-muted-foreground">
        No deliveries yet. They appear here when a task event happens, or send a test event.
      </p>
    )
  }

  return (
    <div className="grid gap-3">
      <DeliveriesList webhookId={webhook.id} deliveries={rows} canResend={webhook.enabled} onSent={onSent} />
      {deliveries.hasNextPage && (
        <Button
          variant="outline"
          className="justify-self-center"
          disabled={deliveries.isFetchingNextPage}
          onClick={() => void deliveries.fetchNextPage()}
        >
          {deliveries.isFetchingNextPage && <Spinner />}
          Load more
        </Button>
      )}
    </div>
  )
}
