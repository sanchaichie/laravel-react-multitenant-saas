'use client'

import type { Organization, User } from '@shared/types/api'
import ThemeToggleButton from '@/components/shell/ThemeToggleButton'
import { UserMenu } from '@/components/shell/UserMenu'
import { Button } from '@/components/ui/button'
import { useSidebar } from '@/components/ui/sidebar'
import { OrganizationSwitcher } from '@/features/organizations/OrganizationSwitcher'
import { MenuTwoIcon, XIcon } from '@/icons'

interface AppHeaderProps {
  user: User
  organizations: Organization[] | null
}

/** TailAdmin's header: the sidebar toggle, the organization switcher, the theme and the user menu. */
export function AppHeader({ user, organizations }: AppHeaderProps) {
  const { openMobile, toggleSidebar } = useSidebar()

  return (
    <header className="sticky top-0 z-50 flex w-full shrink-0 border-b border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
      <div className="flex grow flex-wrap items-center justify-between gap-3 px-3 py-3 xl:px-6">
        <div className="flex min-w-0 flex-1 items-center gap-3">
          <Button
            variant="outline"
            size="icon"
            className="shrink-0 text-gray-500 lg:size-11 dark:text-gray-400"
            onClick={toggleSidebar}
            aria-label="Toggle sidebar"
          >
            {openMobile ? <XIcon className="size-6" /> : <MenuTwoIcon className="size-auto!" />}
          </Button>
          <div className="min-w-0 flex-1 sm:flex-none">
            <OrganizationSwitcher current={user.current_organization} organizations={organizations} />
          </div>
        </div>
        <div className="flex items-center gap-3">
          <ThemeToggleButton />
          <UserMenu user={user} />
        </div>
      </div>
    </header>
  )
}
