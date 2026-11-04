<?php

namespace App\Actions\Admin;

use App\Ai\AiBudget;
use App\Billing\Plans;
use App\Enums\PlatformAuditAction;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\Scopes\OrganizationScope;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

class ShowOrganization
{
    /** Members listed on the page; the count covers all of them. */
    public const MEMBER_LIMIT = 100;

    public function __construct(
        private readonly RecordPlatformAudit $recordAudit,
        private readonly Plans $plans,
        private readonly AiBudget $budget,
    ) {}

    /**
     * One organization for a platform admin: plan, AI budget, counts and members,
     * never task content. It isn't the admin's current organization, so the
     * tenant-owned counts name it explicitly instead of going through the scope.
     * Recorded in the platform log each time it's opened (unless $record is false).
     *
     * @return array{organization: Organization, plan: array<string, mixed>, subscription: array<string, mixed>|null, ai_budget: array<string, mixed>, counts: array<string, int>, members: EloquentCollection<int, User>}
     */
    public function handle(Organization $organization, bool $record = true): array
    {
        $organization->load('subscriptions');

        $members = $organization->members()
            ->orderBy('organization_user.created_at')
            ->orderBy('organization_user.id')
            ->limit(self::MEMBER_LIMIT)
            ->get();
        (new EloquentCollection($members->pluck('membership')))->load('role');

        $subscription = $organization->subscriptions->sortByDesc('id')->first();

        if ($record) {
            $this->recordAudit->handle(PlatformAuditAction::OrganizationViewed, [
                'type' => 'organization',
                'id' => $organization->id,
                'label' => $organization->name,
            ]);
        }

        return [
            'organization' => $organization,
            'plan' => $this->plans->for($organization)->toArray(),
            'subscription' => $subscription === null ? null : [
                'status' => $subscription->stripe_status,
                'ends_at' => $subscription->ends_at,
                'created_at' => $subscription->created_at,
            ],
            'ai_budget' => $this->budget->summary($organization),
            'counts' => [
                'members' => $organization->members()->count(),
                'tasks' => Task::withoutGlobalScope(OrganizationScope::class)
                    ->where('organization_id', $organization->id)->count(),
                'pending_invitations' => OrganizationInvitation::withoutGlobalScope(OrganizationScope::class)
                    ->where('organization_id', $organization->id)->pending()->where('expires_at', '>', now())->count(),
            ],
            'members' => $members,
        ];
    }
}
