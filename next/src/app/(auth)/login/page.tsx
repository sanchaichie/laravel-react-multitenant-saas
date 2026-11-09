import type { Metadata } from 'next'
import Link from 'next/link'
import { AuthPanel } from '@/components/AuthPanel'
import { LoginForm } from '@/features/auth/LoginForm'
import { safeRedirect } from '@/lib/safeRedirect'
import { redirectIfLoggedIn } from '../guest'

export const metadata: Metadata = { title: 'Log in' }

// A Server Component: it runs on the server, reads the query from the `searchParams`
// prop (a Promise in Next 16) and sends HTML. Only LoginForm ships JavaScript.
export default async function LoginPage({ searchParams }: PageProps<'/login'>) {
  const { redirect } = await searchParams
  const redirectTo = safeRedirect(redirect)
  await redirectIfLoggedIn(redirectTo)

  return (
    <AuthPanel
      title="Log in"
      description="Welcome back."
      footer={
        <p>
          No account yet?{' '}
          <Link href="/register" className="text-brand-500 hover:text-brand-600 dark:text-brand-400">
            Create one
          </Link>
        </p>
      }
    >
      <LoginForm redirectTo={redirectTo} />
    </AuthPanel>
  )
}
