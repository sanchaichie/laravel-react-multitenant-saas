<?php

namespace App\Actions\Organizations;

use App\Actions\Audit\RecordAudit;
use App\Actions\Organizations\Concerns\EnsuresAnOwnerRemains;
use App\Enums\AuditAction;
use App\Models\Organization;
use App\Models\Task;
use App\Models\User;
use App\Support\CurrentOrganization;
use Illuminate\Support\Facades\DB;

class RemoveMember
{
    use EnsuresAnOwnerRemains;

    public function __construct(
        private readonly CurrentOrganization $currentOrganization,
        private readonly RecordAudit $recordAudit,
    ) {}

    /**
     * Remove a member (or let them leave). Their tasks here become unassigned. If
     * this was their current organization, move them to another one they belong to
     * (first by name), or to none.
     *
     * @param  User  $member  loaded through $organization->members() (membership attached)
     */
    public function handle(Organization $organization, User $member): void
    {
        DB::transaction(function () use ($organization, $member) {
            $this->lockOrganization($organization);

            $role = $this->lockedRole($organization, $member);
            if ($role->is_owner) {
                $this->ensureAnotherOwner($organization, $member, 'member');
            }

            $organization->members()->detach($member->id);

            // Only this organization's tasks (the scope), with server-chosen values.
            // A builder update skips model events (and Auditable): the entry below
            // counts them instead of one entry per task.
            $this->currentOrganization->run($organization, function () use ($member, $role) {
                $unassigned = Task::query()
                    ->where('assignee_id', $member->id)
                    ->update(['assignee_id' => null]);

                $this->recordAudit->handle(
                    AuditAction::MemberRemoved,
                    ['type' => 'member', 'id' => $member->id, 'label' => $member->name],
                    metadata: [
                        'email' => $member->email,
                        'role' => ['id' => $role->id, 'name' => $role->name],
                        'left' => $member->is(auth()->user()),
                        'unassigned_tasks' => $unassigned,
                    ],
                );
            });

            if ($member->current_organization_id === $organization->id) {
                $member->forceFill([
                    'current_organization_id' => $member->organizations()->orderBy('organizations.name')->orderBy('organizations.id')->first()?->id,
                ])->save();
            }
        });

        $this->currentOrganization->forget();
    }
}
