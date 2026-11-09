import { cookies } from 'next/headers'
import { ClientProviders } from '@/components/ClientProviders'
import { AppHeader } from '@/components/shell/AppHeader'
import { AppSidebar } from '@/components/shell/AppSidebar'
import { VerifyEmailBanner } from '@/components/shell/VerifyEmailBanner'
import { SidebarInset, SidebarProvider } from '@/components/ui/sidebar'
import { getOrganizations, requireUser } from '@/lib/dal'

/**
 * The logged-in pages' layout (a Server Component ↔ web's AppLayout + RequireAuth):
 * it loads the user (with the current organization and role) and their
 * organizations on the server, before any HTML is sent. Pages below call
 * getCurrentUser() again; React's cache() makes that the same request.
 */
export default async function AppLayout({ children }: { children: React.ReactNode }) {
  const user = await requireUser()
  const organizations = await getOrganizations().then(
    (list) => list.data,
    () => null,
  )
  // SidebarProvider saves open/collapsed in a cookie; read it here so a reload keeps it.
  const sidebarOpen = (await cookies()).get('sidebar_state')?.value !== 'false'

  return (
    // The query cache and the API's 401/409 moves, for the header's switcher and the pages.
    <ClientProviders>
      <SidebarProvider defaultOpen={sidebarOpen}>
        <AppSidebar permissions={user.current_organization?.permissions ?? []} />
        <SidebarInset>
          <AppHeader user={user} organizations={organizations} />
          <main className="mx-auto w-full max-w-(--breakpoint-2xl) p-4 md:p-6">
            {!user.email_verified && <VerifyEmailBanner email={user.email} />}
            {children}
          </main>
        </SidebarInset>
      </SidebarProvider>
    </ClientProviders>
  )
}
