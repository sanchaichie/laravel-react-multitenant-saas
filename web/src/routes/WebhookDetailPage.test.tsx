import { screen, waitFor, within } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { acme, beta, jsonResponse, logIn, mockApi, organizationList, renderApp, sentBody } from '@/test/utils'
import { SECRET, deliveryPage, makeDelivery, makeWebhook } from '@/test/webhooks'
import type { WebhookDelivery, WebhookEndpoint } from '@/types/api'

type Handler = (init: RequestInit, url: URL) => Response

function api(webhook: WebhookEndpoint, deliveries: WebhookDelivery[] = [makeDelivery()], overrides: Record<string, Handler> = {}) {
  return mockApi({
    'GET /api/organizations': () => jsonResponse(organizationList([acme, beta])),
    'GET /api/webhooks': () => jsonResponse({ data: [webhook], meta: { max: 5 } }),
    [`GET /api/webhooks/${webhook.id}`]: () => jsonResponse({ data: webhook }),
    [`GET /api/webhooks/${webhook.id}/deliveries`]: () => jsonResponse(deliveryPage(deliveries)),
    ...overrides,
  })
}

describe('WebhookDetailPage', () => {
  it('saves edits, and only once something changed', async () => {
    logIn()
    const webhook = makeWebhook()
    const fetchMock = api(webhook, [], {
      'PATCH /api/webhooks/7': (init) => jsonResponse({ data: { ...webhook, ...(sentBody(init) as object) } }),
    })
    const { user } = renderApp('/webhooks/7')

    const save = await screen.findByRole('button', { name: 'Save changes' })
    expect(save).toBeDisabled()
    await user.click(screen.getByRole('checkbox', { name: 'Task updated' }))
    await user.click(save)

    expect(await screen.findByText('Webhook saved')).toBeInTheDocument()
    const patch = fetchMock.mock.calls.find(([, init]) => init?.method === 'PATCH')
    expect(sentBody(patch![1]!)).toEqual({ url: 'https://hooks.example.com/acme', description: 'CRM sync', events: ['task.created', 'task.deleted'] })
    await waitFor(() => expect(screen.getByRole('button', { name: 'Save changes' })).toBeDisabled())
  })

  it('turns a webhook that kept failing back on', async () => {
    logIn()
    const webhook = makeWebhook({ enabled: false, disabled_reason: 'failing' })
    const fetchMock = api(webhook, [], {
      'PATCH /api/webhooks/7': () => jsonResponse({ data: { ...webhook, enabled: true, disabled_reason: null } }),
    })
    const { user } = renderApp('/webhooks/7')

    expect(await screen.findByText('Turned off because deliveries kept failing')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Send test event' })).toBeDisabled()
    await user.click(screen.getByRole('button', { name: 'Turn on' }))

    expect(await screen.findByText('Webhook turned on')).toBeInTheDocument()
    const patch = fetchMock.mock.calls.find(([, init]) => init?.method === 'PATCH')
    expect(sentBody(patch![1]!)).toEqual({ enabled: true })
  })

  it('rolls the secret after confirming, and shows the new one once', async () => {
    logIn()
    api(makeWebhook(), [], {
      'POST /api/webhooks/7/secret': () => jsonResponse({ data: makeWebhook(), meta: { secret: SECRET } }),
    })
    const { user } = renderApp('/webhooks/7')

    await user.click(await screen.findByRole('button', { name: 'Roll secret' }))
    const confirm = await screen.findByRole('alertdialog', { name: 'Roll the signing secret?' })
    await user.click(within(confirm).getByRole('button', { name: 'Roll secret' }))

    const dialog = await screen.findByRole('dialog', { name: 'Save the signing secret' })
    expect(within(dialog).getByText(SECRET)).toBeInTheDocument()
    await user.click(within(dialog).getByRole('button', { name: "I've saved it" }))
    await waitFor(() => expect(screen.queryByText(SECRET)).not.toBeInTheDocument())
  })

  it('deletes after confirming and goes back to the list', async () => {
    logIn()
    api(makeWebhook(), [], { 'DELETE /api/webhooks/7': () => jsonResponse(null, 204) })
    const { user, router } = renderApp('/webhooks/7')

    await user.click(await screen.findByRole('button', { name: 'Delete' }))
    await user.click(within(await screen.findByRole('alertdialog')).getByRole('button', { name: 'Delete webhook' }))

    await waitFor(() => expect(router.state.location.pathname).toBe('/webhooks'))
    expect(await screen.findByText('Webhook deleted')).toBeInTheDocument()
  })

  it('shows what was sent and what came back, as text', async () => {
    logIn()
    api(makeWebhook(), [
      makeDelivery({ id: 101, status: 'pending', attempts: 2, response_status: 503, response_body: '<h1>Down</h1>', next_attempt_at: '2026-11-02T10:35:00.000000Z' }),
      makeDelivery(),
    ])
    const { user } = renderApp('/webhooks/7')

    // The delivery log's rows (the sidebar has list items too).
    const first = (await screen.findByText('503 · attempt 2 failed, retrying')).closest('li')!
    const rows = [first, screen.getByText('200 · 85 ms').closest('li')!]
    expect(within(rows[0]).getByText('Pending')).toBeInTheDocument()
    expect(within(rows[0]).getByText('503 · attempt 2 failed, retrying')).toBeInTheDocument()
    expect(within(rows[1]).getByText('Delivered')).toBeInTheDocument()
    expect(within(rows[1]).getByText('200 · 85 ms')).toBeInTheDocument()

    await user.click(within(rows[0]).getByRole('button', { name: /Task created/ }))
    // Markup in the payload and the answer stays text.
    expect(await within(rows[0]).findByText(/"title": "Ship <b>it<\/b>"/)).toBeInTheDocument()
    expect(within(rows[0]).getByText('<h1>Down</h1>')).toBeInTheDocument()
    expect(within(rows[0]).getByText('Response (503)')).toBeInTheDocument()
    expect(rows[0].querySelector('b, h1')).toBeNull()
  })

  it('resends a delivery', async () => {
    logIn()
    const fetchMock = api(makeWebhook(), [makeDelivery({ status: 'failed', attempts: 6, response_status: 500 })], {
      'POST /api/webhooks/7/deliveries/100/resend': () => jsonResponse({ data: makeDelivery({ id: 102, status: 'pending', attempts: 0 }) }, 202),
    })
    const { user } = renderApp('/webhooks/7')

    await user.click(await screen.findByRole('button', { name: 'Resend' }))

    expect(await screen.findByText('Sent again')).toBeInTheDocument()
    expect(fetchMock.mock.calls.some(([input, init]) => init?.method === 'POST' && String(input).endsWith('/resend'))).toBe(true)
  })

  it('sends a test event and polls until it has been tried', async () => {
    logIn()
    let answered = false
    const ping = makeDelivery({ id: 103, event: 'ping', status: 'pending', attempts: 0, response_status: null, duration_ms: null })
    const fetchMock = api(makeWebhook(), [], {
      'POST /api/webhooks/7/test': () => jsonResponse({ data: ping }, 202),
      'GET /api/webhooks/7/deliveries': () => {
        const sent = fetchMock.mock.calls.some(([, init]) => init?.method === 'POST')
        if (!sent) return jsonResponse(deliveryPage([]))
        return jsonResponse(deliveryPage([answered ? { ...ping, status: 'succeeded', attempts: 1, response_status: 200, duration_ms: 12 } : ping]))
      },
    })
    const { user } = renderApp('/webhooks/7')

    expect(await screen.findByText(/No deliveries yet/)).toBeInTheDocument()
    await user.click(screen.getByRole('button', { name: 'Send test event' }))
    expect(await screen.findByText('Test event sent')).toBeInTheDocument()
    expect(await screen.findByText('sending…')).toBeInTheDocument()

    answered = true
    expect(await screen.findByText('200 · 12 ms', {}, { timeout: 5000 })).toBeInTheDocument()
    const polls = () => fetchMock.mock.calls.filter(([input]) => String(input).includes('/deliveries')).length
    const settled = polls()
    await new Promise((resolve) => setTimeout(resolve, 3500))
    expect(polls()).toBe(settled)
  }, 15_000)

  it('says not found for another organization\'s webhook or a bad id', async () => {
    logIn()
    mockApi({
      'GET /api/organizations': () => jsonResponse(organizationList([acme, beta])),
      'GET /api/webhooks/99': () => jsonResponse({ message: 'Not found.' }, 404),
    })
    renderApp('/webhooks/99')
    expect(await screen.findByRole('heading', { name: 'Webhook not found' })).toBeInTheDocument()
  })

  it('says not found for an id the API wouldn\'t take, without asking', async () => {
    logIn()
    const fetchMock = mockApi({ 'GET /api/organizations': () => jsonResponse(organizationList([acme, beta])) })
    renderApp('/webhooks/abc')

    expect(await screen.findByRole('heading', { name: 'Webhook not found' })).toBeInTheDocument()
    expect(fetchMock.mock.calls.some(([input]) => String(input).includes('/api/webhooks'))).toBe(false)
  })

  it('stops polling a delivery nobody tries after a minute', async () => {
    vi.useFakeTimers({ shouldAdvanceTime: true })
    try {
      logIn()
      const fetchMock = api(makeWebhook(), [makeDelivery({ status: 'pending', attempts: 0, response_status: null, duration_ms: null })])
      renderApp('/webhooks/7')
      await screen.findByText('sending…')

      await vi.advanceTimersByTimeAsync(61_000)
      const polls = () => fetchMock.mock.calls.filter(([input]) => String(input).includes('/deliveries')).length
      const after = polls()
      await vi.advanceTimersByTimeAsync(10_000)
      expect(polls()).toBe(after)
    } finally {
      vi.useRealTimers()
    }
  })
})
