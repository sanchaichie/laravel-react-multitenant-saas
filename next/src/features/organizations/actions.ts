'use server'

import { revalidatePath } from 'next/cache'
import { redirect } from 'next/navigation'
import type { Organization } from '@shared/types/api'
import { serverApi } from '@/lib/api'
import { errorMessage } from '@/lib/errors'

/**
 * The server checks membership (an organization you're not in is a 404). Then go
 * to the root of the section you were in (/tasks/42?status=done → /tasks): a record
 * id or a filter belongs to the organization you just left. The redirect renders
 * the new page on the server, so the layout reloads the user and their organization.
 */
export async function switchOrganization(organizationId: number, fromPath: string): Promise<{ message: string }> {
  try {
    await serverApi<{ data: Organization }>(`/api/organizations/${organizationId}/switch`, { method: 'POST' })
  } catch (error) {
    return { message: errorMessage(error) }
  }

  // Drop every cached page: they show the organization just left (Back would bring them back).
  revalidatePath('/', 'layout')
  const section = fromPath.split('/')[1] ?? ''
  redirect(/^[a-z-]*$/.test(section) ? `/${section}` : '/')
}
