import type { ReactNode } from 'react'

/** "Nothing here yet" or "something went wrong": an icon, a title, a line and actions. */
export function EmptyState({
  icon,
  title,
  description,
  children,
  role,
}: {
  icon?: ReactNode
  title: string
  description?: string
  children?: ReactNode
  role?: 'alert'
}) {
  return (
    <div role={role} className="flex flex-col items-center gap-3 px-4 py-12 text-center">
      {icon && <div className="text-gray-400 [&_svg]:size-8">{icon}</div>}
      <h2 className="text-lg font-semibold text-gray-800 dark:text-white/90">{title}</h2>
      {description && <p className="max-w-sm text-sm text-gray-500 dark:text-gray-400">{description}</p>}
      {children && <div className="mt-2 flex flex-wrap justify-center gap-2">{children}</div>}
    </div>
  )
}
