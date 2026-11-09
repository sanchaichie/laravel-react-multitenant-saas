import type { ReactNode } from 'react'
import { cn } from '@/utils'

/** TailAdmin's card surface. */
export function Card({ className, children }: { className?: string; children: ReactNode }) {
  return (
    <div className={cn('rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/3', className)}>{children}</div>
  )
}
