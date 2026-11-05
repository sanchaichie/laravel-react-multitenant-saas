import { useState } from 'react'
import { PlusIcon, WebhookIcon } from 'lucide-react'
import { Link, useNavigate } from 'react-router'
import { ErrorState } from '@/components/ErrorState'
import { Button } from '@/components/ui/button'
import { Card, CardContent } from '@/components/ui/card'
import { Skeleton } from '@/components/ui/skeleton'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { useWebhooks } from '@/features/webhooks/api'
import { eventLabel } from '@/features/webhooks/events'
import { SecretDialog } from '@/features/webhooks/SecretDialog'
import { DeliveryStatusBadge, WebhookStatusBadge } from '@/features/webhooks/WebhookBadges'
import { WebhookFormDialog } from '@/features/webhooks/WebhookFormDialog'
import { useCan } from '@/hooks/useCan'
import { ApiError } from '@/lib/api'
import { errorMessage } from '@/lib/apiErrors'
import { formatDateTime } from '@/lib/format'
import { useCurrentOrganization } from '@/stores/auth'
import type { WebhookEndpoint } from '@/types/api'

/** The current organization's webhooks: where task events are sent. */
export function WebhooksPage() {
  const organization = useCurrentOrganization()
  const canManage = useCan('webhooks.manage')
  const webhooks = useWebhooks(canManage)
  const navigate = useNavigate()
  // The secret of the webhook just added: in this page's state only, never the cache,
  // so it's gone once the dialog closes (or the page is left).
  const [created, setCreated] = useState<{ webhook: WebhookEndpoint; secret: string } | null>(null)

  if (!organization) {
    return (
      <ErrorState
        title="No organization selected"
        description="Choose or create one from the switcher in the sidebar to see its webhooks."
      />
    )
  }

  if (!canManage || (webhooks.error instanceof ApiError && webhooks.error.status === 403)) {
    return (
      <ErrorState
        title="You don't have access to webhooks"
        description="Ask an owner for a role with “Manage webhooks”."
      />
    )
  }

  const list = webhooks.data?.data ?? []
  const atLimit = webhooks.data !== undefined && list.length >= webhooks.data.meta.max

  return (
    <div className="grid gap-6">
      <title>Webhooks · React SaaS</title>
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="text-2xl font-semibold">Webhooks</h1>
          <p className="max-w-prose text-sm text-muted-foreground">
            Send {organization.name}'s task changes to another service as they happen: a signed JSON POST to a URL you
            choose.
          </p>
        </div>
        {webhooks.isSuccess && !atLimit && (
          <WebhookFormDialog onCreated={(webhook, secret) => setCreated({ webhook, secret })}>
            <Button>
              <PlusIcon aria-hidden />
              Add webhook
            </Button>
          </WebhookFormDialog>
        )}
      </div>

      <Card>
        <CardContent>
          {webhooks.isError ? (
            <ErrorState title="Could not load the webhooks" description={errorMessage(webhooks.error)}>
              <Button variant="outline" onClick={() => void webhooks.refetch()}>
                Retry
              </Button>
            </ErrorState>
          ) : webhooks.isPending ? (
            <div className="grid gap-2" aria-label="Loading webhooks">
              <Skeleton className="h-9 w-full" />
              <Skeleton className="h-9 w-full" />
            </div>
          ) : list.length === 0 ? (
            <div className="flex flex-col items-center gap-2 px-4 py-12 text-center">
              <WebhookIcon className="size-8 text-muted-foreground" aria-hidden />
              <h2 className="font-medium">No webhooks yet</h2>
              <p className="max-w-sm text-sm text-muted-foreground">
                Add one to tell your CRM, chat or own service when a task is created, updated or deleted.
              </p>
            </div>
          ) : (
            <>
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>Endpoint</TableHead>
                    <TableHead className="hidden sm:table-cell">Events</TableHead>
                    <TableHead>Status</TableHead>
                    <TableHead className="hidden md:table-cell">Last delivery</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {list.map((webhook) => (
                    <TableRow key={webhook.id}>
                      <TableCell className="max-w-64">
                        <Link to={`/webhooks/${webhook.id}`} className="block font-medium break-all hover:underline">
                          {webhook.url ?? webhook.host}
                        </Link>
                        {webhook.description && <p className="text-xs text-muted-foreground">{webhook.description}</p>}
                      </TableCell>
                      <TableCell className="hidden text-sm text-muted-foreground sm:table-cell">
                        {webhook.events.map(eventLabel).join(', ')}
                      </TableCell>
                      <TableCell>
                        <WebhookStatusBadge webhook={webhook} />
                      </TableCell>
                      <TableCell className="hidden md:table-cell">
                        {webhook.last_delivery ? (
                          <span className="flex items-center gap-2 text-sm text-muted-foreground">
                            <DeliveryStatusBadge status={webhook.last_delivery.status} />
                            {formatDateTime(webhook.last_delivery.created_at)}
                          </span>
                        ) : (
                          <span className="text-sm text-muted-foreground">None yet</span>
                        )}
                      </TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
              {atLimit && (
                <p className="mt-3 text-sm text-muted-foreground">
                  An organization can have at most {webhooks.data.meta.max} webhooks. Delete one to add another.
                </p>
              )}
            </>
          )}
        </CardContent>
      </Card>

      <SecretDialog
        secret={created?.secret ?? null}
        onClose={() => {
          const id = created?.webhook.id
          setCreated(null)
          if (id !== undefined) void navigate(`/webhooks/${id}`)
        }}
      />
    </div>
  )
}
