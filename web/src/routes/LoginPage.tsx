import { Link, useSearchParams } from 'react-router'
import { AuthCard } from '@/features/auth/AuthCard'
import { LoginForm } from '@/features/auth/LoginForm'

export function LoginPage() {
  // Keep ?redirect= when switching to the register page.
  const [searchParams] = useSearchParams()
  const query = searchParams.toString()

  return (
    <>
      {/* React 19 moves <title> into <head>, like useSeoMeta. */}
      <title>Log in · React SaaS</title>
      <AuthCard
        title="Log in"
        description="Welcome back."
        footer={
          <p>
            No account yet?{' '}
            <Link to={`/register${query ? `?${query}` : ''}`} className="font-medium text-primary underline-offset-4 hover:underline">
              Create one
            </Link>
          </p>
        }
      >
        <LoginForm />
      </AuthCard>
    </>
  )
}
