'use client'

import { useTransition } from 'react'
import { usePathname } from 'next/navigation'
import { toast } from 'sonner'
import type { Organization } from '@shared/types/api'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuGroup,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown'
import { Spinner } from '@/components/ui/spinner'
import { CheckIcon, ChevronDownIcon } from '@/icons'
import { cn } from '@/utils'
import { switchOrganization } from './actions'

interface OrganizationSwitcherProps {
  current: Organization | null
  /** Loaded on the server with the layout; null if that request failed. */
  organizations: Organization[] | null
}

/** Which organization you're in, and a menu to change it (in the header). */
export function OrganizationSwitcher({ current, organizations }: OrganizationSwitcherProps) {
  const pathname = usePathname()
  // useTransition: isPending while the Server Action and the redirect it starts run.
  const [isPending, startTransition] = useTransition()

  function choose(organizationId: number) {
    if (organizationId === current?.id || isPending) {
      return
    }
    startTransition(async () => {
      // It only returns on failure: success redirects.
      const result = await switchOrganization(organizationId, pathname)
      if (result) {
        toast.error('Could not switch organization', { description: result.message })
      }
    })
  }

  return (
    <DropdownMenu>
      <DropdownMenuTrigger
        aria-label={`Organization: ${current?.name ?? 'none'}`}
        className="flex w-full min-w-0 items-center gap-3 rounded-[10px] border border-gray-200 px-3 py-2 text-start hover:bg-gray-50 sm:w-64 dark:border-gray-800 dark:hover:bg-white/3"
      >
        <span className="flex size-8 shrink-0 items-center justify-center rounded-lg bg-brand-500 text-sm font-semibold text-white">
          {(current?.name ?? '?').slice(0, 1).toUpperCase()}
        </span>
        <span className="grid min-w-0 leading-tight">
          <span className="truncate text-sm font-medium text-gray-800 dark:text-white/90">{current?.name ?? 'No organization'}</span>
          {current && <span className="truncate text-xs text-gray-500 dark:text-gray-400">{current.role.name}</span>}
        </span>
        {isPending ? <Spinner className="ms-auto size-4" /> : <ChevronDownIcon className="ms-auto size-4 shrink-0 text-gray-500" />}
      </DropdownMenuTrigger>
      <DropdownMenuContent align="start" sideOffset={8} className="w-64 p-2">
        {/* Base UI wants a menu's label inside a group (it throws otherwise). */}
        <DropdownMenuGroup>
          <DropdownMenuLabel className="px-2 text-xs text-gray-500">Organizations</DropdownMenuLabel>
          {organizations === null && <DropdownMenuItem disabled>Could not load organizations</DropdownMenuItem>}
          {organizations?.map((organization) => (
            <DropdownMenuItem key={organization.id} onClick={() => choose(organization.id)} className="justify-between gap-2">
              <span className="grid min-w-0">
                <span className="truncate">{organization.name}</span>
                <span className="text-xs text-gray-500 dark:text-gray-400">{organization.role.name}</span>
              </span>
              <CheckIcon className={cn('size-4 text-brand-500', organization.id !== current?.id && 'invisible')} />
            </DropdownMenuItem>
          ))}
        </DropdownMenuGroup>
        <DropdownMenuSeparator />
        <p className="px-2 py-1 text-xs text-gray-500 dark:text-gray-400">
          Create organizations and invite people in the React app for now.
        </p>
      </DropdownMenuContent>
    </DropdownMenu>
  )
}
