<?php

namespace App\Actions\Admin;

use App\Actions\Audit\RecordAudit;
use App\Actions\Organizations\Concerns\EnsuresAnOwnerRemains;
use App\Enums\AuditAction;
use App\Enums\PlatformAuditAction;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Role;
use App\Models\Scopes\OrganizationScope;
use App\Models\User;
use App\Notifications\MadeOwnerByAdmin;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MakeMemberOwner
{
    use EnsuresAnOwnerRemains;

    public function __construct(
        private readonly RecordPlatformAudit $recordPlatformAudit,
        private readonly RecordAudit $recordAudit,
    ) {}

    /**
     * Give an existing member the organization's Owner role, for an organization
     * whose owners are gone or locked out. Never a platform admin as the member (no
     * making yourself, or each other, Owner of someone else's organization). The
     * organization's audit log shows it as done by a platform admin (no actor, no
     * IP); the platform log has who and why. The member gets an email.
     *
     * @return bool false when they were already Owner (nothing changed or logged)
     *
     * @throws ModelNotFoundException not a member of this organization
     * @throws ValidationException the member is a platform admin
     */
    public function handle(Organization $organization, int $memberId, string $reason): bool
    {
        [$changed, $member] = DB::transaction(function () use ($organization, $memberId, $reason) {
            // The order every membership change uses: the organization row, then the
            // memberships (DeleteAccount, ChangeMemberRole, RemoveMember).
            $this->lockOrganization($organization);

            $membership = Membership::query()
                ->where('organization_id', $organization->id)
                ->where('user_id', $memberId)
                ->lockForUpdate()
                ->firstOrFail();
            // Locked (after the membership, the order member actions use), so admin:grant can't slip in.
            $member = User::query()->lockForUpdate()->findOrFail($memberId);

            if ($member->isPlatformAdmin()) {
                throw ValidationException::withMessages(['user_id' => "Platform admins can't be made Owner from the admin area."]);
            }

            $current = $membership->role;
            if ($current->is_owner) {
                return [false, $member];
            }

            // That organization's own Owner role: never one of another organization. Locked
            // FOR SHARE like any role being assigned (LocksRole), though Owner can't be deleted.
            $owner = Role::withoutGlobalScope(OrganizationScope::class)
                ->where('organization_id', $organization->id)
                ->where('is_owner', true)
                ->sharedLock()
                ->firstOrFail();

            $membership->forceFill(['role_id' => $owner->id])->save();

            $this->recordAudit->handle(
                AuditAction::MemberRoleChanged,
                ['type' => 'member', 'id' => $member->id, 'label' => $member->name],
                ['role' => ['old' => ['id' => $current->id, 'name' => $current->name], 'new' => ['id' => $owner->id, 'name' => $owner->name]]],
                organization: $organization,
                byPlatformAdmin: true,
            );

            $this->recordPlatformAudit->handle(
                PlatformAuditAction::OwnerAssigned,
                ['type' => 'organization', 'id' => $organization->id, 'label' => $organization->name],
                [
                    'reason' => $reason,
                    'member_id' => $member->id,
                    'member_email' => $member->email,
                    'old_role' => $current->name,
                ],
            );

            return [true, $member];
        });

        if ($changed) {
            $member->notify(new MadeOwnerByAdmin($organization->name));
        }

        return $changed;
    }
}
