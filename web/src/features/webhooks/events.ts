import type { WebhookDeliveryStatus, WebhookEvent } from '@/types/api'

/** The events an endpoint can subscribe to, in the order the form lists them. */
export const WEBHOOK_EVENTS: { value: WebhookEvent; label: string; description: string }[] = [
  { value: 'task.created', label: 'Task created', description: 'Someone creates a task.' },
  { value: 'task.updated', label: 'Task updated', description: 'A task changes, including when its assignee leaves.' },
  { value: 'task.deleted', label: 'Task deleted', description: 'A task is deleted (it carries the task as it was).' },
]

export function eventLabel(event: string): string {
  if (event === 'ping') return 'Test event'
  return WEBHOOK_EVENTS.find((e) => e.value === event)?.label ?? event
}

export const DELIVERY_STATUS_LABELS: Record<WebhookDeliveryStatus, string> = {
  pending: 'Pending',
  succeeded: 'Delivered',
  failed: 'Failed',
  canceled: 'Not sent',
}
