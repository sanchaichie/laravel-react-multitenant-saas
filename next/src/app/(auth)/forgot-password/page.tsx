import type { Metadata } from 'next'
import Link from 'next/link'
import { AuthPanel } from '@/components/AuthPanel'
import { ForgotPasswordForm } from '@/features/auth/ForgotPasswordForm'
import { redirectIfLoggedIn } from '../guest'

export const metadata: Metadata = { title: 'Forgot password' }

export default async function ForgotPasswordPage() {
  await redirectIfLoggedIn()

  return (
    <AuthPanel
      title="Forgot your password?"
      description="Enter your email and we'll send you a link to set a new one."
      footer={
        <Link href="/login" className="text-brand-500 hover:text-brand-600 dark:text-brand-400">
          Back to log in
        </Link>
      }
    >
      <ForgotPasswordForm />
    </AuthPanel>
  )
}
