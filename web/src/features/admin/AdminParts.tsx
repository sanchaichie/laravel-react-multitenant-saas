import type { ReactNode } from 'react'
import { ArrowLeftIcon } from 'lucide-react'
import { Link } from 'react-router'
import { Button } from '@/components/ui/button'

/** Back to an admin list, above a detail page. */
export function BackLink({ to, children }: { to: string; children: ReactNode }) {
  return (
    <Button variant="ghost" size="sm" className="w-fit" asChild>
      <Link to={to}>
        <ArrowLeftIcon aria-hidden />
        {children}
      </Link>
    </Button>
  )
}

/** One label and value of a detail page (inside a <dl>). */
export function Detail({ label, children }: { label: string; children: ReactNode }) {
  return (
    <div className="grid gap-1">
      <dt className="text-muted-foreground">{label}</dt>
      <dd className="font-medium">{children}</dd>
    </div>
  )
}
