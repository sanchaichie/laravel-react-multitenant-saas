import type { ComponentType } from 'react'
import {
  Building2Icon,
  CreditCardIcon,
  LayoutDashboardIcon,
  ListTodoIcon,
  ScrollTextIcon,
  ShieldIcon,
  SparklesIcon,
  UserSearchIcon,
  UsersIcon,
  WebhookIcon,
} from 'lucide-react'
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
import { useAuth, useCurrentOrganization } from '@/stores/auth'
import type { Permission } from '@/types/api'

interface NavItem {
  to: string
  label: string
  icon: ComponentType<{ 'aria-hidden'?: boolean }>
  /** Shown only to roles with it (the page itself also checks, and the API decides). */
  permission?: Permission
}

const navigation: NavItem[] = [
  { to: '/', label: 'Dashboard', icon: LayoutDashboardIcon },
  { to: '/tasks', label: 'Tasks', icon: ListTodoIcon },
  { to: '/members', label: 'Members', icon: UsersIcon },
  { to: '/roles', label: 'Roles', icon: ShieldIcon },
  { to: '/audit-log', label: 'Audit log', icon: ScrollTextIcon, permission: 'audit_log.view' },
  { to: '/ai-usage', label: 'AI usage', icon: SparklesIcon, permission: 'ai_usage.view' },
  // Also active on /billing/invoices (not an exact match, like Tasks).
  { to: '/billing', label: 'Billing', icon: CreditCardIcon, permission: 'billing.manage' },
  { to: '/webhooks', label: 'Webhooks', icon: WebhookIcon, permission: 'webhooks.manage' },
]

// For people who run the app (user.is_platform_admin), whatever the current organization.
const adminNavigation: NavItem[] = [
  { to: '/admin/users', label: 'Users', icon: UserSearchIcon },
  { to: '/admin/organizations', label: 'Organizations', icon: Building2Icon },
  { to: '/admin/audit-log', label: 'Platform log', icon: ScrollTextIcon },
]

export function AppSidebar() {
  // Filtered during render from the current organization's permissions (a computed
  // feeding v-for): nothing stored, so switching organization updates it by itself.
  const permissions = useCurrentOrganization()?.permissions ?? []
  const items = navigation.filter((item) => !item.permission || permissions.includes(item.permission))
  // A whole group from one flag, derived during render (a v-if on a computed).
  const isPlatformAdmin = useAuth((s) => s.user?.is_platform_admin === true)

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
              {items.map((item) => (
                <NavItemLink key={item.to} item={item} />
              ))}
            </SidebarMenu>
          </SidebarGroupContent>
        </SidebarGroup>
        {isPlatformAdmin && (
          <SidebarGroup>
            <SidebarGroupLabel>Platform admin</SidebarGroupLabel>
            <SidebarGroupContent>
              <SidebarMenu>
                {adminNavigation.map((item) => (
                  <NavItemLink key={item.to} item={item} />
                ))}
              </SidebarMenu>
            </SidebarGroupContent>
          </SidebarGroup>
        )}
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
  // Tasks stays active on /tasks/42; only the Dashboard ('/') needs an exact match.
  const end = item.to === '/'
  const isActive = useMatch({ path: item.to, end }) !== null
  const { setOpenMobile } = useSidebar()
  const Icon = item.icon

  return (
    <SidebarMenuItem>
      <SidebarMenuButton asChild isActive={isActive} tooltip={item.label}>
        {/* NavLink also sets aria-current="page" on the active link. */}
        <NavLink to={item.to} end={end} onClick={() => setOpenMobile(false)}>
          <Icon aria-hidden />
          <span>{item.label}</span>
        </NavLink>
      </SidebarMenuButton>
    </SidebarMenuItem>
  )
}
