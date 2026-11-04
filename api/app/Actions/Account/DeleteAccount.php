<?php

namespace App\Actions\Account;

use App\Actions\Organizations\Concerns\EnsuresAnOwnerRemains;
use App\Actions\Organizations\RemoveMember;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;

class DeleteAccount
{
    use EnsuresAnOwnerRemains;

    public function __construct(private readonly RemoveMember $removeMember) {}

    /**
     * Delete the user (the request checked their password). Refused while they're the
     * last owner of an organization that has other members. Organizations where they're
     * the only member are deleted with their data; they leave the others (audited
     * there), then the user and their tokens are deleted.
     *
     * @throws ValidationException the organizations that block it
     */
    public function handle(User $user): void
    {
        DB::transaction(function () use ($user) {
            $toDelete = collect();
            $toLeave = collect();
            $blocking = collect();

            foreach ($user->organizations()->orderBy('organizations.id')->get() as $organization) {
                $memberships = $this->lockedMemberships($organization, $user);
                $own = $memberships->firstWhere('user_id', $user->id);

                // Removed from it between the listing and the lock: nothing left to do there.
                if ($own === null) {
                    continue;
                }

                $anotherOwner = $memberships->contains(
                    fn (Membership $membership) => $membership->user_id !== $user->id && $membership->role->is_owner,
                );

                match (true) {
                    ! $own->role->is_owner || $anotherOwner => $toLeave->push($organization),
                    $memberships->count() > 1 => $blocking->push($organization),
                    default => $toDelete->push($organization),
                };
            }

            if ($blocking->isNotEmpty()) {
                throw ValidationException::withMessages(['account' => self::blockedMessage($blocking)]);
            }

            foreach ($toLeave as $organization) {
                $this->removeMember->handle($organization, $organization->members()->findOrFail($user->id));
            }

            // Their tasks, roles, summaries, chats, AI usage and audit log go with them
            // (cascading foreign keys).
            $toDelete->each(fn (Organization $organization) => $organization->delete());

            // Joined another organization while this ran (from another device)? Its owner
            // check never happened: start again rather than cascade the membership away.
            $handled = $toLeave->merge($toDelete)->pluck('id')->all();
            if ($user->organizations()->whereNotIn('organizations.id', $handled)->exists()) {
                throw ValidationException::withMessages(['account' => 'Your organizations changed while deleting. Try again.']);
            }

            // The user row before its tokens, the order every other account action
            // locks them in (ConfirmTwoFactor, RevertEmailChange): no deadlock between two tabs.
            User::query()->whereKey($user->id)->lockForUpdate()->first();
            $user->tokens()->delete();
            Password::broker()->deleteToken($user);
            $user->delete();
        });
    }

    /**
     * @param  Collection<int, Organization>  $blocking  last owner, with other members
     */
    private static function blockedMessage(Collection $blocking): string
    {
        $names = $blocking->pluck('name')->join(', ', ' and ');

        return $blocking->count() === 1
            ? "You're the last owner of {$names}, which has other members. Make one of them Owner first."
            : "You're the last owner of {$names}, which have other members. Make one of them Owner in each first.";
    }

    /**
     * Every membership of the organization with its role, as it stands while this
     * decides. The organization row is locked FOR NO KEY UPDATE, as every member action
     * does first (joining, removing, role changes), so the list can't change under us,
     * while inserts that point at the organization (tasks, chats, usage) aren't blocked
     * and can't deadlock with this. Then the owner rows in id order (as
     * EnsuresAnOwnerRemains does) and the user's own row.
     *
     * @return Collection<int, Membership>
     */
    private function lockedMemberships(Organization $organization, User $user): Collection
    {
        $this->lockOrganizationRow($organization->id);

        $memberships = Membership::query()->where('organization_id', $organization->id)->orderBy('id');
        (clone $memberships)->where(fn ($query) => $query
            ->whereHas('role', fn ($role) => $role->where('is_owner', true))
            ->orWhere('user_id', $user->id))
            ->lockForUpdate()->get();
        $memberships = $memberships->get();
        $memberships->load('role');

        return $memberships;
    }
}
