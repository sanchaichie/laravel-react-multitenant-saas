import { screen, within } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { makeInvoice } from '@/test/billing'
import { acme, ada, beta, jsonResponse, logIn, memberRole, mockApi, organizationList, renderApp } from '@/test/utils'
import type { InvoicePage } from '@/types/api'

type Handler = (init: RequestInit, url: URL) => Response

const page = (data: InvoicePage['data'], nextCursor: string | null = null): InvoicePage => ({ data, meta: { next_cursor: nextCursor } })

function api(overrides: Record<string, Handler> = {}) {
  return mockApi({
    'GET /api/organizations': () => jsonResponse(organizationList([acme, beta])),
    'GET /api/billing/invoices': () => jsonResponse(page([makeInvoice()])),
    ...overrides,
  })
}

describe('InvoicesPage', () => {
  it('lists the invoices with their status and Stripe links', async () => {
    logIn()
    api({
      'GET /api/billing/invoices': () =>
        jsonResponse(
          page([
            makeInvoice({ id: 'in_2', number: 'ACME-0002', status: 'open', total: 1000, description: '$10 of AI credits', pdf_url: null, created_at: '2026-10-20T12:00:00Z' }),
            makeInvoice(),
          ]),
        ),
    })
    renderApp('/billing/invoices')

    const table = await screen.findByRole('table', { name: 'Invoices' })
    const [, due, paid] = within(table).getAllByRole('row')
    expect(within(due).getByText('Oct 20, 2026')).toBeInTheDocument()
    expect(within(due).getByText('$10.00')).toBeInTheDocument()
    expect(within(due).getByText('Due')).toBeInTheDocument()
    // An open invoice is paid on Stripe's page.
    const pay = within(due).getByRole('link', { name: 'Pay invoice ACME-0002 (opens in a new tab)' })
    expect(pay).toHaveAttribute('target', '_blank')
    expect(pay).toHaveAttribute('rel', 'noopener noreferrer')
    expect(within(due).queryByRole('link', { name: /PDF/ })).not.toBeInTheDocument()

    expect(within(paid).getByText('$19.00')).toBeInTheDocument()
    expect(within(paid).getByText('Paid')).toBeInTheDocument()
    expect(within(paid).getByRole('link', { name: 'View invoice ACME-0001 (opens in a new tab)' })).toHaveAttribute(
      'href',
      'https://invoice.stripe.com/i/acct_1/test_1',
    )
    expect(within(paid).getByRole('link', { name: 'PDF of invoice ACME-0001 (opens in a new tab)' })).toBeInTheDocument()
  })

  it('loads more with the cursor', async () => {
    logIn()
    const cursors: Array<string | null> = []
    api({
      'GET /api/billing/invoices': (_init, url) => {
        const cursor = url.searchParams.get('starting_after')
        cursors.push(cursor)
        return jsonResponse(
          cursor === null ? page([makeInvoice({ id: 'in_2', number: 'ACME-0002' })], 'in_2') : page([makeInvoice({ id: 'in_1' })]),
        )
      },
    })
    const { user } = renderApp('/billing/invoices')

    await user.click(await screen.findByRole('button', { name: 'Load more' }))

    expect(await screen.findByText('ACME-0001')).toBeInTheDocument()
    expect(screen.getByText('ACME-0002')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Load more' })).not.toBeInTheDocument()
    expect(cursors).toEqual([null, 'in_2'])
  })

  it('says when there are no invoices yet', async () => {
    logIn()
    api({ 'GET /api/billing/invoices': () => jsonResponse(page([])) })
    renderApp('/billing/invoices')

    expect(await screen.findByRole('heading', { name: 'No invoices yet' })).toBeInTheDocument()
  })

  it('shows Stripe being unavailable, with Retry', async () => {
    logIn()
    api({
      'GET /api/billing/invoices': () =>
        jsonResponse({ message: 'Billing is unavailable right now. Try again in a few minutes.', code: 'billing_unavailable' }, 503),
    })
    renderApp('/billing/invoices')

    expect(await screen.findByRole('heading', { name: 'Could not load the invoices' }, { timeout: 3000 })).toBeInTheDocument()
    expect(screen.getByText('Billing is unavailable right now. Try again in a few minutes.')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Retry' })).toBeInTheDocument()
  })

  it('shows the no-access state without billing.manage', async () => {
    logIn({ ...ada, current_organization: { ...acme, role: memberRole, permissions: ['tasks.create'] } })
    const fetchMock = api()
    renderApp('/billing/invoices')

    expect(await screen.findByRole('heading', { name: "You don't have access to billing" })).toBeInTheDocument()
    expect(fetchMock.mock.calls.some(([url]) => String(url).includes('/api/billing'))).toBe(false)
  })
})
