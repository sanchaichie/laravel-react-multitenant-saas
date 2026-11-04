import { Badge } from '@/components/ui/badge'

interface AdminUserBadgesProps {
  user: { email_verified: boolean; two_factor_enabled: boolean; is_platform_admin: boolean; suspended_at: string | null }
}

/** What an admin checks first about a user, as badges (the list and the user page). */
export function AdminUserBadges({ user }: AdminUserBadgesProps) {
  return (
    <span className="flex flex-wrap gap-1">
      {user.suspended_at && <Badge variant="destructive">Suspended</Badge>}
      {user.is_platform_admin && <Badge>Admin</Badge>}
      <Badge variant={user.email_verified ? 'secondary' : 'outline'}>{user.email_verified ? 'Verified' : 'Unverified'}</Badge>
      {user.two_factor_enabled && <Badge variant="secondary">2FA</Badge>}
    </span>
  )
}
