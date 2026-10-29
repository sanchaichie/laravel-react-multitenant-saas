import { Outlet } from 'react-router'
import { AppSidebar } from '@/components/AppSidebar'
import { Separator } from '@/components/ui/separator'
import { SidebarInset, SidebarProvider, SidebarTrigger } from '@/components/ui/sidebar'

/**
 * Layout route for the logged-in pages: the sidebar, then the page. SidebarProvider
 * is a context: every sidebar part below it (and useSidebar()) shares its state.
 */
export function AppLayout() {
  return (
    <SidebarProvider defaultOpen={readSidebarOpen()}>
      <AppSidebar />
      <SidebarInset>
        <header className="flex h-14 shrink-0 items-center gap-2 border-b px-4">
          <SidebarTrigger className="-ml-1" />
          <Separator orientation="vertical" className="mr-2 data-[orientation=vertical]:h-4" />
          <span className="font-semibold">React SaaS</span>
        </header>
        <div className="mx-auto w-full max-w-5xl p-4 md:p-8">
          <Outlet />
        </div>
      </SidebarInset>
    </SidebarProvider>
  )
}

// SidebarProvider writes its open/collapsed state to a cookie, but only reads it
// on a server (Next.js). Read it here so the choice survives a reload.
function readSidebarOpen(): boolean {
  return !document.cookie.split('; ').includes('sidebar_state=false')
}
