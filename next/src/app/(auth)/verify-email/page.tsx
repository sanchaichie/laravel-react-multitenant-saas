import type { Metadata } from 'next'
import { AuthPanel } from '@/components/AuthPanel'
import { VerifyEmail } from '@/features/auth/VerifyEmail'
import { requireUser } from '@/lib/dal'

export const metadata: Metadata = { title: 'Verify your email' }

/**
 * The link from the verification email. It needs the account it was sent to, so
 * logged out it goes through login and back here (with the link's query).
 */
export default async function VerifyEmailPage({ searchParams }: PageProps<'/verify-email'>) {
  const params = await searchParams
  const query = new URLSearchParams(
    Object.entries(params).flatMap(([key, value]) => (typeof value === 'string' ? [[key, value]] : [])),
  )
  await requireUser()

  const link = {
    id: query.get('id') ?? '',
    hash: query.get('hash') ?? '',
    expires: query.get('expires') ?? '',
    signature: query.get('signature') ?? '',
  }

  return (
    <AuthPanel title="Verify your email">
      <VerifyEmail link={Object.values(link).every(Boolean) ? link : null} />
    </AuthPanel>
  )
}
