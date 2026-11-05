import { screen, waitFor, within } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { acme, ada, beta, jsonResponse, logIn, mockApi, organizationList, renderApp, sentBody } from '@/test/utils'
import { SECRET, deliveryPage, makeWebhook } from '@/test/webhooks'
import type { WebhookEndpoint } from '@/types/api'

type Handler = (init: RequestInit, url: URL) => Response

function api(webhooks: WebhookEndpoint[], overrides: Record<string, Handler> = {}) {
  return mockApi({
    'GET /api/organizations': () => jsonResponse(organizationList([acme, beta])),
    'GET /api/webhooks': () => jsonResponse({ data: webhooks, meta: { max: 5 } }),
    ...overrides,
  })
}

describe('WebhooksPage', () => {
  it('lists the webhooks with their status and last delivery', async () => {
    logIn()
    api([
      makeWebhook({ last_delivery: { status: 'failed', event: 'task.updated', created_at: '2026-11-02T10:00:00.000000Z' } }),
      makeWebhook({ id: 8, url: 'https://other.example.com/x', host: 'other.example.com', description: null, enabled: false, disabled_reason: 'failing', events: ['task.deleted'] }),
    ])
    renderApp('/webhooks')

    expect(await screen.findByRole('link', { name: 'https://hooks.example.com/acme' })).toHaveAttribute('href', '/webhooks/7')
    const rows = screen.getAllByRole('row')
    expect(within(rows[1]).getByText('Active')).toBeInTheDocument()
    expect(within(rows[1]).getByText('Failed')).toBeInTheDocument()
    expect(within(rows[1]).getByText('Task created, Task updated, Task deleted')).toBeInTheDocument()
    expect(within(rows[2]).getByText('Off: kept failing')).toBeInTheDocument()
    expect(within(rows[2]).getByText('None yet')).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Webhooks' })).toHaveAttribute('aria-current', 'page')
  })

  it('shows an empty state', async () => {
    logIn()
    api([])
    renderApp('/webhooks')

    expect(await screen.findByRole('heading', { name: 'No webhooks yet' })).toBeInTheDocument()
  })

  it('adds a webhook, shows its secret once with Copy, then opens it', async () => {
    logIn()
    const created = makeWebhook({ id: 9, events: ['task.created', 'task.deleted'] })
    const fetchMock = api([], {
      'POST /api/webhooks': () => jsonResponse({ data: created, meta: { secret: SECRET } }, 201),
      'GET /api/webhooks/9': () => jsonResponse({ data: created }),
      'GET /api/webhooks/9/deliveries': () => jsonResponse(deliveryPage([])),
    })
    const { user, router } = renderApp('/webhooks')

    await user.click(await screen.findByRole('button', { name: 'Add webhook' }))
    const dialog = await screen.findByRole('dialog', { name: 'Add a webhook' })
    // Every event starts ticked; at least one is needed.
    for (const name of ['Task created', 'Task updated', 'Task deleted']) {
      await user.click(within(dialog).getByRole('checkbox', { name }))
    }
    await user.click(within(dialog).getByRole('button', { name: 'Add webhook' }))
    expect(await within(dialog).findByText('Enter the URL to send to.')).toBeInTheDocument()
    expect(within(dialog).getByText('Choose at least one event.')).toBeInTheDocument()

    await user.type(within(dialog).getByLabelText('Endpoint URL'), 'https://hooks.example.com/acme')
    // Ticked out of order, sent in the list's order.
    await user.click(within(dialog).getByRole('checkbox', { name: 'Task deleted' }))
    await user.click(within(dialog).getByRole('checkbox', { name: 'Task created' }))
    await user.click(within(dialog).getByRole('button', { name: 'Add webhook' }))

    const secretDialog = await screen.findByRole('dialog', { name: 'Save the signing secret' })
    expect(within(secretDialog).getByText(SECRET)).toBeInTheDocument()
    const post = fetchMock.mock.calls.find(([, init]) => init?.method === 'POST')
    expect(sentBody(post![1]!)).toEqual({ url: 'https://hooks.example.com/acme', description: null, events: ['task.created', 'task.deleted'] })

    await user.click(within(secretDialog).getByRole('button', { name: 'Copy' }))
    expect(within(secretDialog).getByRole('button', { name: 'Copied' })).toBeInTheDocument()
    expect(await navigator.clipboard.readText()).toBe(SECRET)

    await user.click(within(secretDialog).getByRole('button', { name: "I've saved it" }))
    await waitFor(() => expect(router.state.location.pathname).toBe('/webhooks/9'))
    // Never shown again.
    expect(await screen.findByRole('heading', { name: 'hooks.example.com', level: 1 })).toBeInTheDocument()
    expect(screen.queryByText(SECRET)).not.toBeInTheDocument()
  })

  it('puts the API\'s refusal on the URL field', async () => {
    logIn()
    api([], {
      'POST /api/webhooks': () =>
        jsonResponse({ message: 'The URL must point to a public address.', errors: { url: ['The URL must point to a public address.'] } }, 422),
    })
    const { user } = renderApp('/webhooks')

    await user.click(await screen.findByRole('button', { name: 'Add webhook' }))
    const dialog = await screen.findByRole('dialog', { name: 'Add a webhook' })
    await user.type(within(dialog).getByLabelText('Endpoint URL'), 'https://169.254.169.254/')
    await user.click(within(dialog).getByRole('button', { name: 'Add webhook' }))

    expect(await within(dialog).findByText('The URL must point to a public address.')).toBeInTheDocument()
    expect(within(dialog).getByLabelText('Endpoint URL')).toHaveAttribute('aria-invalid', 'true')
  })

  it('hides Add at the limit', async () => {
    logIn()
    api([1, 2, 3, 4, 5].map((id) => makeWebhook({ id })))
    renderApp('/webhooks')

    expect(await screen.findByText(/at most 5 webhooks/)).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Add webhook' })).not.toBeInTheDocument()
  })

  it('shows no access without webhooks.manage, and no nav item, without asking', async () => {
    logIn({ ...ada, current_organization: { ...acme, permissions: ['tasks.create', 'tasks.manage', 'audit_log.view'] } })
    const fetchMock = mockApi({ 'GET /api/organizations': () => jsonResponse(organizationList([acme])) })
    renderApp('/webhooks')

    expect(await screen.findByText("You don't have access to webhooks")).toBeInTheDocument()
    expect(screen.queryByRole('link', { name: 'Webhooks' })).not.toBeInTheDocument()
    expect(fetchMock.mock.calls.some(([input]) => String(input).includes('/api/webhooks'))).toBe(false)
  })

  it('shows an error with Retry', async () => {
    logIn()
    let fail = true
    api([], {
      'GET /api/webhooks': () => (fail ? jsonResponse({ message: 'Server error' }, 500) : jsonResponse({ data: [], meta: { max: 5 } })),
    })
    const { user } = renderApp('/webhooks')

    expect(await screen.findByRole('heading', { name: 'Could not load the webhooks' }, { timeout: 3000 })).toBeInTheDocument()
    fail = false
    await user.click(screen.getByRole('button', { name: 'Retry' }))
    expect(await screen.findByRole('heading', { name: 'No webhooks yet' })).toBeInTheDocument()
  })
})

describe('WebhooksPage while adding', () => {
  it('keeps the dialog open until the webhook (and its secret) is back', async () => {
    logIn()
    let answer: (response: Response) => void = () => {}
    api([], {
      'POST /api/webhooks': () => new Promise<Response>((resolve) => (answer = resolve)) as unknown as Response,
      'GET /api/webhooks/9': () => jsonResponse({ data: makeWebhook({ id: 9 }) }),
      'GET /api/webhooks/9/deliveries': () => jsonResponse(deliveryPage([])),
    })
    const { user } = renderApp('/webhooks')

    await user.click(await screen.findByRole('button', { name: 'Add webhook' }))
    const dialog = await screen.findByRole('dialog', { name: 'Add a webhook' })
    await user.type(within(dialog).getByLabelText('Endpoint URL'), 'https://hooks.example.com/acme')
    await user.click(within(dialog).getByRole('button', { name: 'Add webhook' }))
    await waitFor(() => expect(within(dialog).getByRole('button', { name: 'Cancel' })).toBeDisabled())

    await user.keyboard('{Escape}')
    expect(screen.getByRole('dialog', { name: 'Add a webhook' })).toBeInTheDocument()

    answer(jsonResponse({ data: makeWebhook({ id: 9 }), meta: { secret: SECRET } }, 201))
    expect(await screen.findByText(SECRET)).toBeInTheDocument()
  })
})
