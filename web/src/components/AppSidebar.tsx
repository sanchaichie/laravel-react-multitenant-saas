import type { ComponentType } from 'react'
import { LayoutDashboardIcon, UsersIcon } from 'lucide-react'
import { NavLink, useMatch } from 'react-router'
import {
  Sidebar,
  SidebarContent,
  SidebarFooter,
  SidebarGroup,
  SidebarGroupContent,
  SidebarGroupLabel,
  SidebarHeader,
  SidebarMenu,
  SidebarMenuButton,
  SidebarMenuItem,
  SidebarRail,
  useSidebar,
} from '@/components/ui/sidebar'
import { UserMenu } from '@/components/UserMenu'
import { OrganizationSwitcher } from '@/features/organizations/OrganizationSwitcher'

interface NavItem {
  to: string
  label: string
  icon: ComponentType<{ 'aria-hidden'?: boolean }>
}

// Tasks (plan 07) joins this list.
const navigation: NavItem[] = [
  { to: '/', label: 'Dashboard', icon: LayoutDashboardIcon },
  { to: '/members', label: 'Members', icon: UsersIcon },
]

export function AppSidebar() {
  return (
    <Sidebar collapsible="icon">
      <SidebarHeader>
        <OrganizationSwitcher />
      </SidebarHeader>
      <SidebarContent>
        <SidebarGroup>
          <SidebarGroupLabel>Navigation</SidebarGroupLabel>
          <SidebarGroupContent>
            <SidebarMenu>
              {navigation.map((item) => (
                <NavItemLink key={item.to} item={item} />
              ))}
            </SidebarMenu>
          </SidebarGroupContent>
        </SidebarGroup>
      </SidebarContent>
      <SidebarFooter>
        <UserMenu />
        <p className="px-2 text-xs text-muted-foreground group-data-[collapsible=icon]:hidden">
          v{__APP_VERSION__} ·{' '}
          <a
            href="https://github.com/dtrinidad-iv/react-saas"
            target="_blank"
            rel="noreferrer"
            className="underline-offset-4 hover:underline"
          >
            Source
          </a>
        </p>
      </SidebarFooter>
      <SidebarRail />
    </Sidebar>
  )
}

function NavItemLink({ item }: { item: NavItem }) {
  const isActive = useMatch({ path: item.to, end: true }) !== null
  const { setOpenMobile } = useSidebar()
  const Icon = item.icon

  return (
    <SidebarMenuItem>
      <SidebarMenuButton asChild isActive={isActive} tooltip={item.label}>
        {/* NavLink also sets aria-current="page" on the active link. */}
        <NavLink to={item.to} end onClick={() => setOpenMobile(false)}>
          <Icon aria-hidden />
          <span>{item.label}</span>
        </NavLink>
      </SidebarMenuButton>
    </SidebarMenuItem>
  )
}
