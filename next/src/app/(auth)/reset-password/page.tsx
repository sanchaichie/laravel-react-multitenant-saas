import type { Metadata } from 'next'
import Link from 'next/link'
import { AuthPanel } from '@/components/AuthPanel'
import { FormMessage } from '@/components/FormMessage'
import { ResetPasswordForm } from '@/features/auth/ResetPasswordForm'
import { redirectIfLoggedIn } from '../guest'

export const metadata: Metadata = { title: 'Set a new password' }

/** The link from the reset email: /reset-password?token=…&email=… */
export default async function ResetPasswordPage({ searchParams }: PageProps<'/reset-password'>) {
  await redirectIfLoggedIn()
  const { token, email } = await searchParams

  return (
    <AuthPanel title="Set a new password" description="Choose a password you don't use anywhere else.">
      {typeof token === 'string' && token && typeof email === 'string' && email ? (
        <ResetPasswordForm token={token} email={email} />
      ) : (
        <div className="space-y-4">
          <FormMessage message="This link is incomplete. Open the link from the email again, or ask for a new one." />
          <Link href="/forgot-password" className="text-sm text-brand-500 hover:text-brand-600 dark:text-brand-400">
            Ask for a new link
          </Link>
        </div>
      )}
    </AuthPanel>
  )
}
