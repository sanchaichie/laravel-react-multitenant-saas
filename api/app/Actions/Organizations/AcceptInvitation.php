<?php

namespace App\Actions\Organizations;

use App\Actions\Audit\RecordAudit;
use App\Actions\Organizations\Concerns\EnsuresAnOwnerRemains;
use App\Enums\AuditAction;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\Role;
use App\Models\Scopes\OrganizationScope;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class AcceptInvitation
{
    use EnsuresAnOwnerRemains;

    public function __construct(
        private readonly SwitchOrganization $switchOrganization,
        private readonly RecordAudit $recordAudit,
    ) {}

    /**
     * Join the invitation's organization with its role and switch to it. The link
     * reached the invited inbox, so this also verifies the user's email.
     *
     * @throws NotFoundHttpException unknown, revoked, expired or already used
     * @throws AuthorizationException the invitation is for another email address
     */
    public function handle(User $user, string $token): Organization
    {
        return DB::transaction(function () use ($user, $token) {
            // The organization, its role, then the invitation: the same order as the
            // member actions and DeleteRole (EnsuresAnOwnerRemains), so they can't
            // deadlock. Locked FOR SHARE, the role can't be deleted until we're done;
            // if it already was, the invitation has lost it and isn't usable.
            $unlocked = OrganizationInvitation::findByToken($token);
            $organization = $unlocked === null ? null : static::lockOrganizationRow($unlocked->organization_id);
            $roleLocked = $unlocked?->role_id !== null
                && Role::query()->withoutGlobalScope(OrganizationScope::class)->whereKey($unlocked->role_id)->sharedLock()->first() !== null;
            $invitation = $organization === null || ! $roleLocked ? null : OrganizationInvitation::findByToken($token, lock: true);

            static::ensureUsableBy($invitation, $user);

            // Already a member (added another way): keep the existing role.
            if (! $user->isMemberOf($organization)) {
                $organization->members()->attach($user, ['role_id' => $invitation->role_id]);

                // In the invitation's organization, not the one the user acts in now.
                $role = $invitation->role;
                $this->recordAudit->handle(
                    AuditAction::MemberJoined,
                    ['type' => 'member', 'id' => $user->id, 'label' => $user->name],
                    metadata: ['email' => $invitation->email, 'role' => ['id' => $role->id, 'name' => $role->name]],
                    organization: $organization,
                );
            }

            $invitation->forceFill(['accepted_at' => now()])->save();

            if (! $user->hasVerifiedEmail()) {
                $user->markEmailAsVerified();
            }

            return $this->switchOrganization->handle($user, $organization->id);
        });
    }

    /**
     * @phpstan-assert OrganizationInvitation $invitation
     */
    public static function ensureUsableBy(?OrganizationInvitation $invitation, User $user): void
    {
        if ($invitation === null || ! $invitation->isUsable()) {
            throw new NotFoundHttpException('This invitation is no longer valid.');
        }

        if ($user->email !== $invitation->email) {
            throw new AuthorizationException('This invitation was sent to a different email address.');
        }
    }
}
