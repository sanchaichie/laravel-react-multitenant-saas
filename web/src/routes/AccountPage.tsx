import { DeleteAccountCard } from '@/features/account/DeleteAccountCard'
import { DevicesCard } from '@/features/account/DevicesCard'
import { EmailCard } from '@/features/account/EmailCard'
import { PasswordCard } from '@/features/account/PasswordCard'
import { ProfileCard } from '@/features/account/ProfileCard'
import { TwoFactorCard } from '@/features/account/TwoFactorCard'
import { useAuth } from '@/stores/auth'

/** Your own account, whatever organization is current (or none). */
export function AccountPage() {
  // RequireAuth only renders this with a user.
  const user = useAuth((s) => s.user)
  if (!user) return null

  return (
    <div className="grid max-w-3xl gap-6">
      <title>Account settings · React SaaS</title>
      <div>
        <h1 className="text-2xl font-semibold">Account settings</h1>
        <p className="text-sm text-muted-foreground">Your name, email, password and devices, across all your organizations.</p>
      </div>
      {/* key: a fresh form when the saved name changes elsewhere (another tab, /me reload). */}
      <ProfileCard key={user.name} user={user} />
      <EmailCard user={user} />
      <PasswordCard />
      <TwoFactorCard user={user} />
      <DevicesCard />
      <DeleteAccountCard />
    </div>
  )
}
