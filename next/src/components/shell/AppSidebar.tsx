'use client'

import Image from 'next/image'
import Link from 'next/link'
import { usePathname } from 'next/navigation'
import type { ReactNode } from 'react'
import type { Permission } from '@shared/types/api'
import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader, SidebarMenu, SidebarMenuItem, useSidebar } from '@/components/ui/sidebar'
import { LayoutGridIcon, TaskIcon } from '@/icons'
import { cn } from '@/utils'

interface NavItem {
  href: string
  label: string
  icon: ReactNode
  /** Shown only to roles with it (the page also checks, and the API decides). */
  permission?: Permission
}

// The pages the Next app has so far; members, roles, audit log and the rest live
// in the React app until they're ported.
const navigation: NavItem[] = [
  { href: '/', label: 'Overview', icon: <LayoutGridIcon className="size-6" /> },
  { href: '/tasks', label: 'Tasks', icon: <TaskIcon className="size-6" /> },
]

/** TailAdmin's sidebar, with this app's navigation. */
export function AppSidebar({ permissions }: { permissions: Permission[] }) {
  const { open, isMobile, isHovered } = useSidebar()
  const pathname = usePathname()
  const showContent = open || isHovered || isMobile
  const items = navigation.filter((item) => !item.permission || permissions.includes(item.permission))

  return (
    <Sidebar side="left" collapsible="icon">
      <SidebarHeader className="px-5 py-8">
        <Link href="/" className={cn('flex items-center gap-3', showContent ? 'justify-start' : 'justify-center')}>
          <Image src="/images/logo/logo-icon.svg" alt="" width={32} height={32} priority />
          {showContent && <span className="text-lg font-semibold text-gray-800 dark:text-white/90">React SaaS</span>}
        </Link>
      </SidebarHeader>

      <SidebarContent className="overflow-y-auto px-5 duration-300 ease-linear">
        <nav aria-label="Main">
          <p className={cn('mb-4 text-xs leading-5 text-gray-400 uppercase', !showContent && 'sr-only')}>Menu</p>
          <SidebarMenu className="gap-1">
            {items.map((item) => {
              // Tasks stays active on /tasks/42; only the Overview ('/') needs an exact match.
              const active = item.href === '/' ? pathname === '/' : pathname === item.href || pathname.startsWith(`${item.href}/`)
              return (
                <SidebarMenuItem key={item.href}>
                  <Link
                    href={item.href}
                    aria-current={active ? 'page' : undefined}
                    className={cn(
                      'group menu-item',
                      active ? 'menu-item-active' : 'menu-item-inactive',
                      showContent ? 'lg:justify-start' : 'lg:justify-center',
                    )}
                  >
                    <span className={active ? 'menu-item-icon-active' : 'menu-item-icon-inactive'}>{item.icon}</span>
                    {showContent ? <span className="menu-item-text">{item.label}</span> : <span className="sr-only">{item.label}</span>}
                  </Link>
                </SidebarMenuItem>
              )
            })}
          </SidebarMenu>
        </nav>
      </SidebarContent>

      {showContent && (
        <SidebarFooter className="p-0 px-5 pt-1 pb-4">
          <div className="rounded-2xl bg-gray-50 px-4 py-5 text-center dark:bg-white/3">
            <p className="mb-1 text-sm font-semibold text-gray-900 dark:text-white">Next.js frontend</p>
            <p className="text-xs text-gray-500 dark:text-gray-400">
              Server Components and Server Actions on the same Laravel API as the React app.
            </p>
          </div>
        </SidebarFooter>
      )}
    </Sidebar>
  )
}
