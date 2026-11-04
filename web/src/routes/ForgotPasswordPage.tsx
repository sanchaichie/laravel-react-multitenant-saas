import { Link } from 'react-router'
import { AuthCard } from '@/features/auth/AuthCard'
import { ForgotPasswordForm } from '@/features/auth/ForgotPasswordForm'

export function ForgotPasswordPage() {
  return (
    <>
      <title>Forgot password · React SaaS</title>
      <AuthCard
        title="Forgot your password?"
        description="Enter your account's email and we'll send you a link to set a new one."
        footer={
          <p>
            Remembered it?{' '}
            <Link to="/login" className="font-medium text-primary underline-offset-4 hover:underline">
              Log in
            </Link>
          </p>
        }
      >
        <ForgotPasswordForm />
      </AuthCard>
    </>
  )
}
