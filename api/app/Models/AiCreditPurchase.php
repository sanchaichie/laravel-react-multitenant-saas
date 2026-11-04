<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An AI credit pack the organization paid for, written by the Stripe webhook
 * (GrantAiCredits). Adds to the AI budget of the UTC month it was bought in.
 *
 * @property int $id
 * @property int $organization_id
 * @property int|null $user_id
 * @property string $pack
 * @property string $amount_usd
 * @property string $stripe_checkout_session_id
 */
class AiCreditPurchase extends Model
{
    use BelongsToOrganization;

    protected function casts(): array
    {
        return [
            'amount_usd' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
