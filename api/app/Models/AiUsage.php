<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One AI call, written by RecordingAiClient. AiBudget sums its cost per month; AiUsageReport breaks it down.
 *
 * @property int $id
 * @property int $organization_id
 * @property int|null $user_id
 * @property string $feature
 * @property string $model
 * @property int $input_tokens
 * @property int $output_tokens
 * @property string|null $cost_usd
 * @property string $status succeeded or failed
 * @property string|null $error_code
 */
class AiUsage extends Model
{
    use BelongsToOrganization;

    protected $table = 'ai_usage';

    // Append-only log: no updated_at.
    const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'cost_usd' => 'decimal:6',
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
