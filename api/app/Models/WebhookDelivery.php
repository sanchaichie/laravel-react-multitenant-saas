<?php

namespace App\Models;

use App\Enums\WebhookDeliveryStatus;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Scopes\OrganizationScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One event sent to one endpoint, with what came back. The payload is fixed when
 * the event happens; a resend is a new row with the same event_id and payload.
 * Every column is set by the actions and DeliverWebhook (no mass assignment).
 *
 * @property int $id
 * @property int $organization_id
 * @property int $webhook_endpoint_id
 * @property string $event_id
 * @property string $event
 * @property array<string, mixed> $payload
 * @property WebhookDeliveryStatus $status
 * @property int $attempts
 * @property int|null $response_status
 * @property string|null $response_body
 * @property string|null $error
 * @property int|null $duration_ms
 * @property Carbon|null $next_attempt_at
 * @property Carbon|null $delivered_at
 * @property Carbon $created_at
 */
class WebhookDelivery extends Model
{
    use BelongsToOrganization, MassPrunable;

    public const PER_PAGE = 20;

    protected $attributes = [
        'status' => 'pending',
        'attempts' => 0,
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'status' => WebhookDeliveryStatus::class,
            'attempts' => 'integer',
            'response_status' => 'integer',
            'duration_ms' => 'integer',
            'next_attempt_at' => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<WebhookEndpoint, $this>
     */
    public function endpoint(): BelongsTo
    {
        return $this->belongsTo(WebhookEndpoint::class, 'webhook_endpoint_id');
    }

    /**
     * Old deliveries of every organization: the scheduler has no current organization.
     *
     * @return Builder<self>
     */
    public function prunable(): Builder
    {
        return static::withoutGlobalScope(OrganizationScope::class)
            ->where('created_at', '<', now()->subDays(max(1, (int) config('webhooks.retention_days'))));
    }
}
