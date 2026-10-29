import { Link, useSearchParams } from 'react-router'
import { AuthCard } from '@/features/auth/AuthCard'
import { RegisterForm } from '@/features/auth/RegisterForm'

export function RegisterPage() {
  const [searchParams] = useSearchParams()
  const query = searchParams.toString()

  return (
    <>
      <title>Create an account · React SaaS</title>
      <AuthCard
        title="Create an account"
        footer={
          <p>
            Already have one?{' '}
            <Link to={`/login${query ? `?${query}` : ''}`} className="font-medium text-primary underline-offset-4 hover:underline">
              Log in
            </Link>
          </p>
        }
      >
        <RegisterForm />
      </AuthCard>
    </>
  )
}
