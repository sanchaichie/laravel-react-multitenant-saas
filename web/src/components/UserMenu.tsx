import { ChevronsUpDownIcon, CircleUserIcon, EyeOffIcon, LogOutIcon, PaletteIcon, SettingsIcon } from 'lucide-react'
import { Link } from 'react-router'
import { useTheme } from 'next-themes'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuRadioGroup,
  DropdownMenuRadioItem,
  DropdownMenuSeparator,
  DropdownMenuSub,
  DropdownMenuSubContent,
  DropdownMenuSubTrigger,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import { SidebarMenu, SidebarMenuButton, SidebarMenuItem, useSidebar } from '@/components/ui/sidebar'
import { useStopSupportView } from '@/features/admin/api'
import { useLogout } from '@/features/auth/api'
import { useAuth } from '@/stores/auth'

/** The bottom of the sidebar: who's logged in, appearance, log out. */
export function UserMenu() {
  const user = useAuth((s) => s.user)
  const logout = useLogout()
  // In a platform admin's support view, logging out ends the view instead.
  const stopViewing = useStopSupportView()
  const { isMobile } = useSidebar()
  // next-themes: a context (ThemeProvider in AppProviders) that stores the choice
  // in localStorage and puts the `dark` class on <html>.
  const { theme, setTheme } = useTheme()

  if (!user) {
    return null
  }

  return (
    <SidebarMenu>
      <SidebarMenuItem>
        <DropdownMenu>
          <DropdownMenuTrigger asChild>
            <SidebarMenuButton size="lg" tooltip={user.name} aria-label={`Account: ${user.name}`}>
              <CircleUserIcon className="size-5!" aria-hidden />
              <div className="grid min-w-0 flex-1 text-left text-sm leading-tight">
                <span className="truncate font-medium">{user.name}</span>
                <span className="truncate text-xs text-muted-foreground">{user.email}</span>
              </div>
              <ChevronsUpDownIcon className="ml-auto" />
            </SidebarMenuButton>
          </DropdownMenuTrigger>
          <DropdownMenuContent className="min-w-56" side={isMobile ? 'top' : 'right'} align="end" sideOffset={4}>
            <DropdownMenuLabel className="grid font-normal">
              <span className="truncate font-medium">{user.name}</span>
              <span className="truncate text-xs text-muted-foreground">{user.email}</span>
            </DropdownMenuLabel>
            <DropdownMenuSeparator />
            <DropdownMenuItem asChild>
              <Link to="/account">
                <SettingsIcon />
                Account settings
              </Link>
            </DropdownMenuItem>
            <DropdownMenuSub>
              <DropdownMenuSubTrigger>
                <PaletteIcon />
                Appearance
              </DropdownMenuSubTrigger>
              <DropdownMenuSubContent>
                <DropdownMenuRadioGroup value={theme} onValueChange={setTheme}>
                  <DropdownMenuRadioItem value="light">Light</DropdownMenuRadioItem>
                  <DropdownMenuRadioItem value="dark">Dark</DropdownMenuRadioItem>
                  <DropdownMenuRadioItem value="system">System</DropdownMenuRadioItem>
                </DropdownMenuRadioGroup>
              </DropdownMenuSubContent>
            </DropdownMenuSub>
            <DropdownMenuSeparator />
            {user.impersonated_by ? (
              <DropdownMenuItem onSelect={() => stopViewing.mutate()} disabled={stopViewing.isPending}>
                <EyeOffIcon />
                Stop viewing
              </DropdownMenuItem>
            ) : (
              <DropdownMenuItem onSelect={() => logout.mutate()} disabled={logout.isPending}>
                <LogOutIcon />
                Log out
              </DropdownMenuItem>
            )}
          </DropdownMenuContent>
        </DropdownMenu>
      </SidebarMenuItem>
    </SidebarMenu>
  )
}
