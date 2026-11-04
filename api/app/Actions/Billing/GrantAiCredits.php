<?php

namespace App\Actions\Billing;

use App\Actions\Audit\RecordAudit;
use App\Billing\CreditPacks;
use App\Enums\AuditAction;
use App\Models\AiCreditPurchase;
use App\Models\Organization;
use App\Support\CurrentOrganization;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Turns a paid Stripe Checkout session for a credit pack into credits. Called by
 * the webhook; idempotent (one purchase per session, so Stripe retries and the
 * completed + async_payment_succeeded pair grant once). Refunds and disputes
 * don't take credits back.
 */
class GrantAiCredits
{
    public function __construct(
        private readonly CreditPacks $packs,
        private readonly RecordAudit $recordAudit,
        private readonly CurrentOrganization $currentOrganization,
    ) {}

    /**
     * @param  array<string, mixed>  $session  the Checkout Session object from the event
     */
    public function handle(Organization $organization, array $session): ?AiCreditPurchase
    {
        // Subscription checkouts are synced by the subscription events; unpaid ones
        // (e.g. a bank transfer still pending) arrive again as async_payment_succeeded.
        if (($session['mode'] ?? null) !== 'payment' || ($session['payment_status'] ?? null) !== 'paid') {
            return null;
        }

        // The amount comes from our config, never from the event.
        $pack = $this->packs->find((string) ($session['metadata']['pack'] ?? ''));

        // Our checkout sets client_reference_id to the organization: a session for this
        // customer that our app didn't start for this organization grants nothing.
        $startedHere = ($session['client_reference_id'] ?? null) === (string) $organization->id;

        if ($pack === null || ! $startedHere || ! is_string($session['id'] ?? null)) {
            Log::warning('Paid Stripe checkout without a known credit pack for this organization; nothing granted.', [
                'organization_id' => $organization->id,
                'session' => $session['id'] ?? null,
            ]);

            return null;
        }

        return DB::transaction(function () use ($organization, $session, $pack) {
            // One grant at a time per organization, so two deliveries of the same session
            // can't both insert.
            // FOR NO KEY UPDATE, as member actions and DeleteAccount lock it: they wait for
            // each other, while inserts that point at the organization (tasks, usage) don't.
            if (Organization::query()->whereKey($organization->id)->lock('for no key update')->first() === null) {
                // Deleted after the checkout started (its last member left): nobody to credit.
                Log::warning('Paid Stripe checkout for an organization that no longer exists; nothing granted.', ['session' => $session['id']]);

                return null;
            }

            return $this->currentOrganization->run($organization, function () use ($organization, $session, $pack) {
                $purchase = AiCreditPurchase::query()->where('stripe_checkout_session_id', $session['id'])->first();

                if ($purchase !== null) {
                    return $purchase;
                }

                // Only a member of the organization counts as the buyer (it's our own
                // metadata, but they may have left since).
                $userId = filter_var($session['metadata']['user_id'] ?? null, FILTER_VALIDATE_INT);
                $buyer = $userId === false ? null : $organization->members()->whereKey($userId)->first();

                $purchase = new AiCreditPurchase;
                $purchase->forceFill([
                    'user_id' => $buyer?->id,
                    'pack' => $pack->key,
                    'amount_usd' => $pack->amountUsd,
                    'stripe_checkout_session_id' => $session['id'],
                ])->save();

                $this->recordAudit->handle(
                    AuditAction::AiCreditsPurchased,
                    ['type' => 'organization', 'id' => $organization->id, 'label' => $organization->name],
                    metadata: ['source' => 'stripe', 'pack' => $pack->name, 'amount_usd' => $pack->amountUsd],
                    organization: $organization,
                    actor: $buyer,
                    withoutActor: $buyer === null,
                );

                return $purchase;
            });
        });
    }
}
