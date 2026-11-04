<?php

namespace App\Enums;

/**
 * What a platform audit log entry records (RecordPlatformAudit). Only platform
 * admins see these; organizations never do.
 */
enum PlatformAuditAction: string
{
    case AdminGranted = 'admin.granted';
    case AdminRevoked = 'admin.revoked';
    case UserViewed = 'admin.user_viewed';
    case OrganizationViewed = 'admin.organization_viewed';
    case TwoFactorReset = 'admin.two_factor_reset';
    case UserSignedOut = 'admin.user_signed_out';
    case UserSuspended = 'admin.user_suspended';
    case UserUnsuspended = 'admin.user_unsuspended';
    case OwnerAssigned = 'admin.owner_assigned';
    case ImpersonationStarted = 'admin.impersonation_started';
    case ImpersonationEnded = 'admin.impersonation_ended';

    public function label(): string
    {
        return match ($this) {
            self::AdminGranted => 'Made a platform admin',
            self::AdminRevoked => 'Removed a platform admin',
            self::UserViewed => 'Viewed a user',
            self::OrganizationViewed => 'Viewed an organization',
            self::TwoFactorReset => "Reset a user's two-factor",
            self::UserSignedOut => 'Signed a user out everywhere',
            self::UserSuspended => 'Suspended a user',
            self::UserUnsuspended => 'Unsuspended a user',
            self::OwnerAssigned => 'Made a member Owner',
            self::ImpersonationStarted => 'Started viewing as a user',
            self::ImpersonationEnded => 'Stopped viewing as a user',
        };
    }
}
