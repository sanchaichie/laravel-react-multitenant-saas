import type { Metadata } from 'next'
import Link from 'next/link'
import { AuthPanel } from '@/components/AuthPanel'
import { RegisterForm } from '@/features/auth/RegisterForm'
import { redirectIfLoggedIn } from '../guest'

export const metadata: Metadata = { title: 'Create an account' }

export default async function RegisterPage() {
  await redirectIfLoggedIn()

  return (
    <AuthPanel
      title="Create an account"
      description="You get a personal organization to start with."
      footer={
        <p>
          Already have an account?{' '}
          <Link href="/login" className="text-brand-500 hover:text-brand-600 dark:text-brand-400">
            Log in
          </Link>
        </p>
      }
    >
      <RegisterForm />
    </AuthPanel>
  )
}
