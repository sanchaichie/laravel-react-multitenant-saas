import { Badge } from '@/components/ui/badge'
import type { WebhookDeliveryStatus, WebhookEndpoint } from '@/types/api'
import { DELIVERY_STATUS_LABELS } from './events'

export function DeliveryStatusBadge({ status }: { status: WebhookDeliveryStatus }) {
  const variant = status === 'failed' ? 'destructive' : status === 'succeeded' ? 'secondary' : 'outline'
  return <Badge variant={variant}>{DELIVERY_STATUS_LABELS[status]}</Badge>
}

export function WebhookStatusBadge({ webhook }: { webhook: WebhookEndpoint }) {
  if (webhook.enabled) return <Badge variant="secondary">Active</Badge>
  return webhook.disabled_reason === 'failing' ? <Badge variant="destructive">Off: kept failing</Badge> : <Badge variant="outline">Off</Badge>
}
