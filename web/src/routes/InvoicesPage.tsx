import { ArrowLeftIcon, ExternalLinkIcon, ReceiptTextIcon } from 'lucide-react'
import { Link } from 'react-router'
import { ErrorState } from '@/components/ErrorState'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent } from '@/components/ui/card'
import { Skeleton } from '@/components/ui/skeleton'
import { Spinner } from '@/components/ui/spinner'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { useInvoices } from '@/features/billing/api'
import { useCan } from '@/hooks/useCan'
import { ApiError } from '@/lib/api'
import { errorMessage } from '@/lib/apiErrors'
import { formatDate } from '@/lib/format'
import { useCurrentOrganization } from '@/stores/auth'
import type { Invoice, InvoiceStatus } from '@/types/api'

/** The organization's invoices, read live from Stripe, 20 at a time ("Load more"). */
export function InvoicesPage() {
  const organization = useCurrentOrganization()
  const canManage = useCan('billing.manage')
  const invoices = useInvoices(canManage)

  if (!organization) {
    return (
      <ErrorState
        title="No organization selected"
        description="Choose or create one from the switcher in the sidebar to see its invoices."
      />
    )
  }

  if (!canManage || (invoices.error instanceof ApiError && invoices.error.status === 403)) {
    return (
      <ErrorState
        title="You don't have access to billing"
        description="Ask an owner for a role with “Manage the plan, payment details and invoices”."
      />
    )
  }

  // Every page loaded so far, in order (pages of one infinite query).
  const rows = invoices.data?.pages.flatMap((page) => page.data) ?? []

  return (
    <div className="grid gap-6">
      <title>Invoices · React SaaS</title>
      <div className="grid gap-2">
        <Button variant="link" size="sm" className="justify-self-start px-0" asChild>
          <Link to="/billing">
            <ArrowLeftIcon aria-hidden />
            Billing
          </Link>
        </Button>
        <div>
          <h1 className="text-2xl font-semibold">Invoices</h1>
          <p className="text-sm text-muted-foreground">
            {organization.name}'s invoices and receipts from Stripe, newest first. Each opens on Stripe.
          </p>
        </div>
      </div>

      {invoices.isError && rows.length === 0 ? (
        <Card>
          <CardContent>
            <ErrorState title="Could not load the invoices" description={errorMessage(invoices.error)}>
              <Button variant="outline" onClick={() => void invoices.refetch()}>
                Retry
              </Button>
            </ErrorState>
          </CardContent>
        </Card>
      ) : invoices.isPending ? (
        <div className="grid gap-2" aria-label="Loading invoices">
          <Skeleton className="h-10 w-full" />
          <Skeleton className="h-10 w-full" />
          <Skeleton className="h-10 w-full" />
        </div>
      ) : rows.length === 0 && !invoices.hasNextPage ? (
        <Card>
          <CardContent>
            <div className="flex flex-col items-center gap-3 px-4 py-12 text-center">
              <ReceiptTextIcon className="size-8 text-muted-foreground" aria-hidden />
              <h2 className="text-lg font-semibold">No invoices yet</h2>
              <p className="max-w-sm text-sm text-muted-foreground">They appear here after the first payment.</p>
            </div>
          </CardContent>
        </Card>
      ) : (
        <Card>
          <CardContent className="grid gap-4">
            {rows.length === 0 ? (
              // Stripe's page held only drafts, which aren't listed: there may be more.
              <p className="text-sm text-muted-foreground">No finished invoices on this page. Load more to see older ones.</p>
            ) : (
              <InvoiceTable invoices={rows} />
            )}
            {invoices.isFetchNextPageError && (
              <p role="alert" className="text-sm text-destructive">
                Could not load more: {errorMessage(invoices.error)}
              </p>
            )}
            {invoices.hasNextPage && (
              <Button
                variant="outline"
                className="justify-self-center"
                onClick={() => void invoices.fetchNextPage()}
                disabled={invoices.isFetchingNextPage}
              >
                {invoices.isFetchingNextPage && <Spinner />}
                Load more
              </Button>
            )}
          </CardContent>
        </Card>
      )}
    </div>
  )
}

function InvoiceTable({ invoices }: { invoices: Invoice[] }) {
  return (
    <Table aria-label="Invoices">
      <TableHeader>
        <TableRow>
          <TableHead>Date</TableHead>
          <TableHead className="hidden sm:table-cell">Number</TableHead>
          <TableHead className="hidden sm:table-cell">Description</TableHead>
          <TableHead className="text-right">Amount</TableHead>
          <TableHead>Status</TableHead>
          <TableHead>
            <span className="sr-only">Links</span>
          </TableHead>
        </TableRow>
      </TableHeader>
      <TableBody>
        {invoices.map((invoice) => (
          <TableRow key={invoice.id}>
            <TableCell>{formatDate(invoice.created_at)}</TableCell>
            <TableCell className="hidden sm:table-cell">{invoice.number ?? '—'}</TableCell>
            <TableCell className="hidden whitespace-normal sm:table-cell">{invoice.description ?? '—'}</TableCell>
            <TableCell className="text-right tabular-nums">{formatAmount(invoice.total, invoice.currency)}</TableCell>
            <TableCell>
              <Badge variant={statusVariant[invoice.status]}>{statusLabel[invoice.status]}</Badge>
            </TableCell>
            <TableCell>
              <div className="flex justify-end gap-1">
                {invoice.hosted_url && (
                  <ExternalLink href={invoice.hosted_url} label={`${invoice.status === 'open' ? 'Pay' : 'View'} invoice ${invoice.number ?? ''}`}>
                    {invoice.status === 'open' ? 'Pay' : 'View'}
                  </ExternalLink>
                )}
                {invoice.pdf_url && (
                  <ExternalLink href={invoice.pdf_url} label={`PDF of invoice ${invoice.number ?? ''}`}>
                    PDF
                  </ExternalLink>
                )}
              </div>
            </TableCell>
          </TableRow>
        ))}
      </TableBody>
    </Table>
  )
}

/**
 * One of Stripe's pages, in a new tab. The API only passes on https links on
 * stripe.com; noopener so that page can't reach back into this one.
 */
function ExternalLink({ href, label, children }: { href: string; label: string; children: string }) {
  return (
    <Button variant="ghost" size="sm" asChild>
      <a href={href} target="_blank" rel="noopener noreferrer" aria-label={`${label.trim()} (opens in a new tab)`}>
        {children}
        <ExternalLinkIcon aria-hidden />
      </a>
    </Button>
  )
}

const statusLabel: Record<InvoiceStatus, string> = { paid: 'Paid', open: 'Due', void: 'Void', uncollectible: 'Uncollectible' }
const statusVariant: Record<InvoiceStatus, 'default' | 'secondary' | 'destructive' | 'outline'> = {
  paid: 'secondary',
  open: 'destructive',
  void: 'outline',
  uncollectible: 'outline',
}

/** Cents in the invoice's own currency, with a fixed locale. */
function formatAmount(cents: number, currency: string): string {
  try {
    return new Intl.NumberFormat('en-US', { style: 'currency', currency: currency.toUpperCase() }).format(cents / 100)
  } catch {
    // An unknown currency code: still show the number.
    return `${(cents / 100).toFixed(2)} ${currency.toUpperCase()}`
  }
}
