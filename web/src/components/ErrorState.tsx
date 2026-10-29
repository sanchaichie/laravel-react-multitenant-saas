import type { ReactNode } from 'react'
import { TriangleAlertIcon } from 'lucide-react'

interface ErrorStateProps {
  title: string
  description?: string
  /** Buttons or links to recover (Retry, Go home). */
  children?: ReactNode
}

export function ErrorState({ title, description, children }: ErrorStateProps) {
  return (
    <div role="alert" className="flex flex-col items-center gap-3 px-4 py-16 text-center">
      <TriangleAlertIcon className="size-8 text-muted-foreground" aria-hidden />
      <h1 className="text-lg font-semibold">{title}</h1>
      {description && <p className="max-w-sm text-sm text-muted-foreground">{description}</p>}
      {children && <div className="mt-2 flex gap-2">{children}</div>}
    </div>
  )
}
