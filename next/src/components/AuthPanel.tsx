import type { ReactNode } from 'react'

interface AuthPanelProps {
  title: string
  description?: string
  footer?: ReactNode
  children: ReactNode
}

/** The left half of the auth pages (TailAdmin's sign-in layout): a title, the form, a footer line. */
export function AuthPanel({ title, description, footer, children }: AuthPanelProps) {
  return (
    <div className="flex w-full flex-1 flex-col lg:w-1/2">
      <div className="mx-auto flex w-full max-w-md flex-1 flex-col justify-center py-10">
        <div className="mb-5 sm:mb-8">
          <h1 className="mb-2 text-title-sm font-semibold text-gray-800 dark:text-white/90">{title}</h1>
          {description && <p className="text-sm text-gray-500 dark:text-gray-400">{description}</p>}
        </div>
        {children}
        {footer && <div className="mt-5 text-center text-sm text-gray-700 sm:text-start dark:text-gray-400">{footer}</div>}
      </div>
    </div>
  )
}
