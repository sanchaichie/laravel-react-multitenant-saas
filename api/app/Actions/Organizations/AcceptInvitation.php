<?php

namespace App\Actions\Organizations;

use App\Actions\Organizations\Concerns\EnsuresAnOwnerRemains;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class AcceptInvitation
{
    use EnsuresAnOwnerRemains;

    public function __construct(private readonly SwitchOrganization $switchOrganization) {}

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
            // The organization first, then the invitation: the same order as the
            // member actions (EnsuresAnOwnerRemains), so they can't deadlock.
            $organizationId = OrganizationInvitation::findByToken($token)?->organization_id;
            $organization = $organizationId === null ? null : static::lockOrganizationRow($organizationId);
            $invitation = $organization === null ? null : OrganizationInvitation::findByToken($token, lock: true);

            static::ensureUsableBy($invitation, $user);

            // Already a member (added another way): keep the existing role.
            if (! $user->isMemberOf($organization)) {
                $organization->members()->attach($user, ['role' => $invitation->role]);
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
