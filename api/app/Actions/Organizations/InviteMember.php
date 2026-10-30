<?php

namespace App\Actions\Organizations;

use App\Actions\Organizations\Concerns\LocksRole;
use App\Mail\OrganizationInvitationMail;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\Role;
use App\Models\User;
use App\Support\CurrentOrganization;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class InviteMember
{
    use LocksRole;

    public function __construct(private readonly CurrentOrganization $currentOrganization) {}

    /**
     * Invite an email address to the organization and queue the invitation email.
     * Inviting the same address again replaces its pending invitation (new link and
     * expiry), unless that one is for a role the inviter couldn't give.
     *
     * $organization must be the current organization: the invitation's
     * organization_id comes from it (BelongsToOrganization).
     */
    public function handle(User $inviter, Organization $organization, string $email, Role $role): OrganizationInvitation
    {
        $organization->role($role);
        $email = Str::lower(trim($email));

        if ($organization->members()->where('email', $email)->exists()) {
            throw ValidationException::withMessages([
                'email' => 'This person is already a member of the organization.',
            ]);
        }

        $token = Str::random(48);

        try {
            $invitation = $this->createInvitation($inviter, $email, $role, $token);
        } catch (UniqueConstraintViolationException) {
            // Another invitation to this address was created at the same moment
            // (organization_invitations_pending_unique).
            throw ValidationException::withMessages([
                'email' => 'This address was just invited. Reload to see the pending invitation.',
            ]);
        }

        // Plain values, so the queued mail needs nothing from the database.
        Mail::to($email)->queue(new OrganizationInvitationMail(
            organizationName: $organization->name,
            inviterName: $inviter->name,
            roleName: $role->name,
            url: config('app.frontend_url').'/join/'.$token,
            expiresAt: $invitation->expires_at->toDayDateTimeString().' UTC',
        ));

        return $invitation->load('inviter');
    }

    private function createInvitation(User $inviter, string $email, Role $role, string $token): OrganizationInvitation
    {
        return DB::transaction(function () use ($inviter, $email, $role, $token) {
            // Before the invitation rows: see lockRole().
            $actor = $this->currentOrganization->role();
            $role = $this->lockRole($role, 'role_id', $actor);
            $existing = OrganizationInvitation::pending()->where('email', $email)->lockForUpdate()->get();

            // One whose role was deleted can be replaced by anyone who may invite.
            if ($existing->contains(fn (OrganizationInvitation $pending) => $actor === null || ($pending->role !== null && ! $actor->includes($pending->role)))) {
                throw ValidationException::withMessages([
                    'email' => 'This address already has a pending invitation for a role you can\'t give.',
                ]);
            }

            OrganizationInvitation::query()->whereKey($existing->modelKeys())->delete();

            return OrganizationInvitation::create([
                'email' => $email,
                'role_id' => $role->id,
                'token_hash' => OrganizationInvitation::hashToken($token),
                'invited_by' => $inviter->id,
                'expires_at' => now()->addDays(OrganizationInvitation::LIFETIME_DAYS),
            ]);
        });
    }
}
