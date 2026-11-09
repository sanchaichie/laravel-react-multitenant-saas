'use client'

import { useState, useTransition } from 'react'
import type { User } from '@shared/types/api'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown'
import { useTheme } from '@/context/theme-context'
import { logout } from '@/features/auth/actions'
import { ChevronDownIcon, LogOutIcon, MoonIcon, SunIcon } from '@/icons'
import { cn, getInitials } from '@/utils'

/** Who's logged in, appearance, log out (TailAdmin's user dropdown). */
export function UserMenu({ user }: { user: User }) {
  const [open, setOpen] = useState(false)
  const { theme, toggleTheme } = useTheme()
  const [loggingOut, startLogout] = useTransition()

  return (
    <DropdownMenu open={open} onOpenChange={setOpen}>
      <DropdownMenuTrigger aria-label={`Account: ${user.name}`} className="flex items-center gap-3 text-gray-700 dark:text-gray-400">
        <span className="flex size-11 shrink-0 items-center justify-center rounded-full bg-brand-50 text-sm font-semibold text-brand-600 dark:bg-brand-500/15 dark:text-brand-400">
          {getInitials(user.name)}
        </span>
        <span className="hidden max-w-40 truncate text-sm font-medium sm:block">{user.name}</span>
        <ChevronDownIcon className={cn('size-5 text-gray-500 duration-200', open && 'rotate-180')} />
      </DropdownMenuTrigger>
      <DropdownMenuContent align="end" sideOffset={12} className="w-65 p-3">
        <div className="px-3 py-2">
          <span className="block truncate text-sm font-medium text-gray-700 dark:text-gray-300">{user.name}</span>
          <span className="mt-0.5 block truncate text-xs text-gray-500 dark:text-gray-400">{user.email}</span>
        </div>
        <DropdownMenuSeparator />
        <DropdownMenuItem onClick={toggleTheme} closeOnClick={false}>
          {theme === 'dark' ? <SunIcon className="size-5" /> : <MoonIcon className="size-5" />}
          {theme === 'dark' ? 'Light mode' : 'Dark mode'}
        </DropdownMenuItem>
        <DropdownMenuSeparator />
        {/* A Server Action called like a function: it clears the cookie and redirects to /login. */}
        <DropdownMenuItem variant="destructive" disabled={loggingOut} onClick={() => startLogout(() => logout())}>
          <LogOutIcon className="size-5" />
          Log out
        </DropdownMenuItem>
      </DropdownMenuContent>
    </DropdownMenu>
  )
}
