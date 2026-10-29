import { useRef, useState } from 'react'
import { Building2Icon, ChevronsUpDownIcon, PlusIcon } from 'lucide-react'
import { toast } from 'sonner'
import {
  DropdownMenu,
  DropdownMenuCheckboxItem,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import { SidebarMenu, SidebarMenuButton, SidebarMenuItem, useSidebar } from '@/components/ui/sidebar'
import { Spinner } from '@/components/ui/spinner'
import { errorMessage } from '@/lib/apiErrors'
import { useCurrentOrganization } from '@/stores/auth'
import { roleLabel, useOrganizations, useSwitchOrganization } from './api'
import { CreateOrganizationDialog } from './CreateOrganizationDialog'

/** The top of the sidebar: which organization you're in, and a menu to change it. */
export function OrganizationSwitcher() {
  const current = useCurrentOrganization()
  const organizations = useOrganizations()
  const switchOrganization = useSwitchOrganization()
  const { isMobile, setOpenMobile } = useSidebar()
  const [createOpen, setCreateOpen] = useState(false)
  // useRef ↔ a template ref: a handle on the DOM button, here to give it focus back.
  const triggerRef = useRef<HTMLButtonElement>(null)

  const name = current?.name ?? 'No organization'

  function choose(organizationId: number) {
    if (organizationId === current?.id || switchOrganization.isPending) {
      return
    }

    switchOrganization.mutate(organizationId, {
      onSuccess: () => setOpenMobile(false),
      onError: (error) => {
        toast.error('Could not switch organization', { description: errorMessage(error) })
        // Membership may have changed: show the server's view.
        void organizations.refetch()
      },
    })
  }

  return (
    <SidebarMenu>
      <SidebarMenuItem>
        {/* Refetch on open, so organizations joined or left elsewhere show up. */}
        <DropdownMenu onOpenChange={(open) => open && void organizations.refetch()}>
          {/* asChild: the trigger's behavior goes onto SidebarMenuButton itself,
              instead of wrapping it in another button. */}
          <DropdownMenuTrigger asChild>
            <SidebarMenuButton ref={triggerRef} size="lg" tooltip={name} aria-label={`Organization: ${name}`}>
              <div className="flex aspect-square size-8 shrink-0 items-center justify-center rounded-lg bg-sidebar-primary text-sidebar-primary-foreground">
                <Building2Icon className="size-4" aria-hidden />
              </div>
              <div className="grid min-w-0 flex-1 text-left text-sm leading-tight">
                <span className="truncate font-medium">{name}</span>
                {current && <span className="truncate text-xs text-muted-foreground">{roleLabel(current.role)}</span>}
              </div>
              {switchOrganization.isPending ? <Spinner className="ml-auto" /> : <ChevronsUpDownIcon className="ml-auto" />}
            </SidebarMenuButton>
          </DropdownMenuTrigger>
          <DropdownMenuContent
            className="w-(--radix-dropdown-menu-trigger-width) min-w-60"
            align="start"
            side={isMobile ? 'bottom' : 'right'}
            sideOffset={4}
          >
            <DropdownMenuLabel className="text-xs text-muted-foreground">Organizations</DropdownMenuLabel>
            {organizations.isPending && <DropdownMenuItem disabled>Loading…</DropdownMenuItem>}
            {organizations.isError && <DropdownMenuItem disabled>Could not load organizations</DropdownMenuItem>}
            {organizations.data?.data.map((organization) => (
              <DropdownMenuCheckboxItem
                key={organization.id}
                checked={organization.id === current?.id}
                onSelect={() => choose(organization.id)}
              >
                <div className="grid min-w-0">
                  <span className="truncate">{organization.name}</span>
                  <span className="text-xs text-muted-foreground">{roleLabel(organization.role)}</span>
                </div>
              </DropdownMenuCheckboxItem>
            ))}
            <DropdownMenuSeparator />
            <DropdownMenuItem onSelect={() => setCreateOpen(true)}>
              <PlusIcon />
              Create organization
            </DropdownMenuItem>
          </DropdownMenuContent>
        </DropdownMenu>
        <CreateOrganizationDialog
          open={createOpen}
          returnFocusTo={triggerRef}
          onOpenChange={(open) => {
            setCreateOpen(open)
            if (!open) {
              setOpenMobile(false)
            }
          }}
        />
      </SidebarMenuItem>
    </SidebarMenu>
  )
}
