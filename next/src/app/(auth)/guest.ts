import 'server-only'
import { redirect } from 'next/navigation'
import { getCurrentUser } from '@/lib/dal'

/** Logged-out pages (log in, register, ...): a logged-in user goes on to the app instead. */
export async function redirectIfLoggedIn(to = '/'): Promise<void> {
  if (await getCurrentUser()) {
    redirect(to)
  }
}
