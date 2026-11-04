import { Link, useSearchParams } from 'react-router'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import { AuthCard } from '@/features/auth/AuthCard'
import { ResetPasswordForm } from '@/features/auth/ResetPasswordForm'

/** The link from the reset email: /reset-password?token=…&email=… */
export function ResetPasswordPage() {
  // Read on every render, never copied into state: the link's values are the source.
  const [searchParams] = useSearchParams()
  const token = searchParams.get('token') ?? ''
  const email = searchParams.get('email') ?? ''

  return (
    <>
      <title>Set a new password · React SaaS</title>
      <AuthCard title="Set a new password" description="Choose a password you don't use anywhere else.">
        {token && email ? (
          <ResetPasswordForm token={token} email={email} />
        ) : (
          <Alert variant="destructive">
            <AlertTitle>This link is incomplete</AlertTitle>
            <AlertDescription>
              Open the link from the email again, or{' '}
              <Link to="/forgot-password" className="font-medium underline underline-offset-4">
                ask for a new one
              </Link>
              .
            </AlertDescription>
          </Alert>
        )}
      </AuthCard>
    </>
  )
}
