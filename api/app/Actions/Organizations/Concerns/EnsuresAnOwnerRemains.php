<?php

namespace App\Actions\Organizations\Concerns;

use App\Enums\OrganizationRole;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Validation\ValidationException;

trait EnsuresAnOwnerRemains
{
    /**
     * Lock the organization row. Every transaction that changes memberships
     * (here and AcceptInvitation) takes it first, so they run one at a time.
     */
    protected function lockOrganization(Organization $organization): void
    {
        static::lockOrganizationRow($organization->id);
    }

    /**
     * FOR NO KEY UPDATE, not FOR UPDATE: it still serializes the member actions,
     * but doesn't block the KEY SHARE lock Postgres takes on this row for every
     * insert whose foreign key points here (an invitation, a task). FOR UPDATE
     * would, and could deadlock with them (e.g. re-inviting while accepting).
     */
    public static function lockOrganizationRow(int $organizationId): ?Organization
    {
        return Organization::query()->whereKey($organizationId)->lock('for no key update')->first();
    }

    /**
     * Fail if $member is the organization's only owner. Call inside a transaction,
     * after lockOrganization(): the owner rows are locked in id order, so two owners
     * demoting each other at once wait for one another instead of both succeeding.
     */
    protected function ensureAnotherOwner(Organization $organization, User $member, string $errorKey): void
    {
        $owners = Membership::query()
            ->where('organization_id', $organization->id)
            ->where('role', OrganizationRole::Owner)
            ->orderBy('id')
            ->lockForUpdate()
            ->pluck('user_id');

        if ($owners->reject(fn (int $userId) => $userId === $member->id)->isEmpty()) {
            throw ValidationException::withMessages([
                $errorKey => 'An organization needs at least one owner. Make someone else an owner first.',
            ]);
        }
    }

    /**
     * The member's role, read again under a lock (inside the transaction). The
     * policy approved the action for the role it saw before the lock: if that
     * changed meanwhile (say, an admin acting on someone just made an owner),
     * refuse rather than act on a role the actor might not include.
     */
    protected function lockedRole(Organization $organization, User $member): OrganizationRole
    {
        $role = Membership::query()
            ->where('organization_id', $organization->id)
            ->where('user_id', $member->id)
            ->lockForUpdate()
            ->firstOrFail()
            ->role;

        if ($role !== $member->membership->role) {
            throw ValidationException::withMessages([
                'member' => "{$member->name}'s role just changed. Reload the page and try again.",
            ]);
        }

        return $role;
    }
}
