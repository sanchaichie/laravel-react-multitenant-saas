<?php

namespace App\Actions\Billing;

use App\Actions\Audit\RecordAudit;
use App\Billing\Plans;
use App\Enums\AuditAction;
use App\Models\Organization;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Wraps a webhook's subscription update: if the organization's plan as Stripe
 * reports it is different afterwards, records it in the audit log. Comparing plans
 * (not events) keeps it idempotent: Stripe retries, and events that change nothing
 * record nothing. The organization row is locked, so its webhooks run one at a time.
 */
class TrackPlanChange
{
    public function __construct(
        private readonly Plans $plans,
        private readonly RecordAudit $recordAudit,
    ) {}

    /**
     * Runs $update under the organization's lock, so checks it makes see every webhook
     * that ran before it. Returns its result, or null (without running it) when the
     * organization was deleted meanwhile.
     *
     * @template T
     *
     * @param  Closure(Organization): T  $update
     * @return T|null
     */
    public function handle(Organization $organization, Closure $update): mixed
    {
        return DB::transaction(function () use ($organization, $update) {
            // FOR NO KEY UPDATE, as member actions and DeleteAccount lock it: they wait for
            // each other, while inserts that point at the organization (tasks, usage) don't.
            if (Organization::query()->whereKey($organization->id)->lock('for no key update')->first() === null) {
                return null;
            }

            $before = $this->plans->reportedFor($organization->load('subscriptions'));
            $result = $update($organization);
            $after = $this->plans->reportedFor($organization->load('subscriptions'));

            $paid = $this->plans->paidSubscriptions($organization);
            if ($paid->count() > 1) {
                Log::warning('Organization has more than one paid plan subscription; refund the extra one in Stripe.', [
                    'organization_id' => $organization->id,
                    'subscriptions' => $paid->pluck('stripe_id')->all(),
                ]);
            }

            if ($before->key !== $after->key) {
                // Stripe changed it (a payment, a cancel, a failed renewal): no actor.
                $this->recordAudit->handle(
                    AuditAction::PlanChanged,
                    ['type' => 'organization', 'id' => $organization->id, 'label' => $organization->name],
                    changes: ['plan' => ['old' => $before->name, 'new' => $after->name]],
                    metadata: ['source' => 'stripe'],
                    organization: $organization,
                    withoutActor: true,
                );
            }

            return $result;
        });
    }
}
