import type { ReactNode } from 'react'
import { ChevronLeftIcon, ChevronRightIcon } from 'lucide-react'
import { Link } from 'react-router'
import { Button } from '@/components/ui/button'
import { Pagination, PaginationContent, PaginationItem } from '@/components/ui/pagination'

interface AdminPaginationProps {
  currentPage: number
  lastPage: number
  /** The address of a page (pushes a history entry, so Back returns). */
  pageLink: (page: number) => string
}

export function AdminPagination({ currentPage, lastPage, pageLink }: AdminPaginationProps) {
  if (lastPage <= 1) {
    return null
  }

  return (
    <Pagination>
      <PaginationContent className="gap-2">
        <PaginationItem>
          <PageLink to={currentPage > 1 ? pageLink(currentPage - 1) : null}>
            <ChevronLeftIcon aria-hidden />
            Previous
          </PageLink>
        </PaginationItem>
        <PaginationItem className="px-2 text-sm text-muted-foreground" aria-live="polite">
          Page {currentPage} of {lastPage}
        </PaginationItem>
        <PaginationItem>
          <PageLink to={currentPage < lastPage ? pageLink(currentPage + 1) : null}>
            Next
            <ChevronRightIcon aria-hidden />
          </PageLink>
        </PaginationItem>
      </PaginationContent>
    </Pagination>
  )
}

function PageLink({ to, children }: { to: string | null; children: ReactNode }) {
  if (to === null) {
    return (
      <Button variant="ghost" size="sm" disabled>
        {children}
      </Button>
    )
  }

  return (
    <Button variant="ghost" size="sm" asChild>
      <Link to={to}>{children}</Link>
    </Button>
  )
}
