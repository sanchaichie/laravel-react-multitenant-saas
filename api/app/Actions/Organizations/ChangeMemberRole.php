<?php

namespace App\Actions\Organizations;

use App\Actions\Audit\RecordAudit;
use App\Actions\Organizations\Concerns\EnsuresAnOwnerRemains;
use App\Actions\Organizations\Concerns\LocksRole;
use App\Enums\AuditAction;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Support\CurrentOrganization;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ChangeMemberRole
{
    use EnsuresAnOwnerRemains, LocksRole;

    public function __construct(
        private readonly CurrentOrganization $currentOrganization,
        private readonly RecordAudit $recordAudit,
    ) {}

    /**
     * @param  User  $member  loaded through $organization->members() (membership attached)
     * @param  Role  $role  one of $organization's roles
     * @return User the member, re-loaded with the new role
     */
    public function handle(Organization $organization, User $member, Role $role): User
    {
        $organization->role($role);

        DB::transaction(function () use ($organization, $member, $role) {
            $this->lockOrganization($organization);
            $actor = $this->currentOrganization->role();
            $role = $this->lockRole($role, 'role_id', $actor);
            $current = $this->lockedRole($organization, $member);

            // The same for the role they have now (MembershipPolicy checks both).
            if (! $actor?->includes($current)) {
                throw ValidationException::withMessages([
                    'role_id' => "{$member->name}'s role just changed. Reload the page and try again.",
                ]);
            }

            if ($current->is_owner && ! $role->is_owner) {
                $this->ensureAnotherOwner($organization, $member, 'role_id');
            }

            if ($current->id === $role->id) {
                return;
            }

            $organization->members()->updateExistingPivot($member->id, ['role_id' => $role->id]);

            // $current was read under the lock, so it's the role they really had.
            $this->recordAudit->handle(
                AuditAction::MemberRoleChanged,
                ['type' => 'member', 'id' => $member->id, 'label' => $member->name],
                ['role' => ['old' => ['id' => $current->id, 'name' => $current->name], 'new' => ['id' => $role->id, 'name' => $role->name]]],
            );
        });

        // The acting user may have changed their own role.
        $this->currentOrganization->forget();

        return $organization->members()->findOrFail($member->id);
    }
}
