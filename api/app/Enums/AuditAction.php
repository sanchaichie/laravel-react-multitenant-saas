<?php

namespace App\Enums;

/**
 * What an audit log entry records. Model changes come from the Auditable trait
 * ("{auditName}.{event}"); the rest are recorded by their Actions with RecordAudit.
 */
enum AuditAction: string
{
    case OrganizationCreated = 'organization.created';
    case MemberInvited = 'member.invited';
    case InvitationRevoked = 'invitation.revoked';
    case MemberJoined = 'member.joined';
    case MemberRoleChanged = 'member.role_changed';
    case MemberRemoved = 'member.removed';
    case RoleCreated = 'role.created';
    case RoleUpdated = 'role.updated';
    case RoleDeleted = 'role.deleted';
    case TaskCreated = 'task.created';
    case TaskUpdated = 'task.updated';
    case TaskDeleted = 'task.deleted';
    case SummaryRequested = 'summary.requested';
    case SummaryApproved = 'summary.approved';
    case SummaryRejected = 'summary.rejected';

    public function label(): string
    {
        return match ($this) {
            self::OrganizationCreated => 'Created the organization',
            self::MemberInvited => 'Invited someone',
            self::InvitationRevoked => 'Revoked an invitation',
            self::MemberJoined => 'Joined',
            self::MemberRoleChanged => 'Changed a member\'s role',
            self::MemberRemoved => 'Removed a member',
            self::RoleCreated => 'Created a role',
            self::RoleUpdated => 'Edited a role',
            self::RoleDeleted => 'Deleted a role',
            self::TaskCreated => 'Created a task',
            self::TaskUpdated => 'Edited a task',
            self::TaskDeleted => 'Deleted a task',
            self::SummaryRequested => 'Requested a task summary',
            self::SummaryApproved => 'Approved a task summary',
            self::SummaryRejected => 'Rejected a task summary',
        };
    }

    public function group(): string
    {
        return match ($this) {
            self::OrganizationCreated => 'Organization',
            self::MemberInvited, self::InvitationRevoked, self::MemberJoined,
            self::MemberRoleChanged, self::MemberRemoved => 'Members',
            self::RoleCreated, self::RoleUpdated, self::RoleDeleted => 'Roles',
            self::TaskCreated, self::TaskUpdated, self::TaskDeleted,
            self::SummaryRequested, self::SummaryApproved, self::SummaryRejected => 'Tasks',
        };
    }
}
