import type { CursorPage, WebhookDelivery, WebhookEndpoint } from '@/types/api'

export const SECRET = 'whsec_0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef'

export function makeWebhook(overrides: Partial<WebhookEndpoint> = {}): WebhookEndpoint {
  return {
    id: 7,
    url: 'https://hooks.example.com/acme',
    url_hidden: false,
    host: 'hooks.example.com',
    description: 'CRM sync',
    events: ['task.created', 'task.updated', 'task.deleted'],
    enabled: true,
    disabled_reason: null,
    disabled_at: null,
    last_delivery: null,
    created_at: '2026-11-01T09:00:00.000000Z',
    ...overrides,
  }
}

export function makeDelivery(overrides: Partial<WebhookDelivery> = {}): WebhookDelivery {
  return {
    id: 100,
    event_id: '9b7c1f0e-2a3d-4c5b-8e6f-7a8b9c0d1e2f',
    event: 'task.created',
    status: 'succeeded',
    attempts: 1,
    response_status: 200,
    response_body: 'ok',
    error: null,
    duration_ms: 85,
    next_attempt_at: null,
    delivered_at: '2026-11-02T10:00:01.000000Z',
    created_at: '2026-11-02T10:00:00.000000Z',
    payload: {
      id: '9b7c1f0e-2a3d-4c5b-8e6f-7a8b9c0d1e2f',
      type: 'task.created',
      created_at: '2026-11-02T10:00:00Z',
      data: { task: { id: 42, title: 'Ship <b>it</b>' } },
    },
    ...overrides,
  }
}

export function deliveryPage(data: WebhookDelivery[], nextCursor: string | null = null): CursorPage<WebhookDelivery> {
  return { data, meta: { next_cursor: nextCursor, prev_cursor: null, per_page: 20, path: '/api/webhooks/7/deliveries' } }
}
