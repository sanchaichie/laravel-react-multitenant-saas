<?php

namespace App\Models;

use App\Enums\WebhookEvent;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * Where an organization's webhooks go. The secret signs every delivery: it's shown
 * once (creating, rolling), stored encrypted, and never serialized. The URL can
 * itself be a secret (a token in its path), so the audit log keeps only its host.
 *
 * @property int $id
 * @property int $organization_id
 * @property string $url
 * @property string|null $description
 * @property list<string> $events WebhookEvent values
 * @property string $secret
 * @property Carbon|null $disabled_at
 * @property string|null $disabled_reason manual, failing
 * @property int $consecutive_failures
 * @property Carbon $created_at
 */
#[Fillable(['url', 'description', 'events'])]
class WebhookEndpoint extends Model
{
    use Auditable, BelongsToOrganization;

    public const DISABLED_MANUALLY = 'manual';

    public const DISABLED_FAILING = 'failing';

    protected $hidden = ['secret'];

    /** Changed by deliveries, not people. */
    protected array $auditExclude = ['consecutive_failures', 'disabled_reason'];

    protected function casts(): array
    {
        return [
            'events' => 'array',
            'secret' => 'encrypted',
            'disabled_at' => 'datetime',
            'consecutive_failures' => 'integer',
        ];
    }

    public function auditName(): string
    {
        return 'webhook';
    }

    public function auditLabel(): ?string
    {
        return $this->host();
    }

    /**
     * The URL as its host only (its path may hold a token), and disabled_at as enabled.
     *
     * @param  array<string, array{old: mixed, new: mixed}>  $changes
     * @return array<string, array{old: mixed, new: mixed}>
     */
    protected function auditChanges(array $changes): array
    {
        if (isset($changes['url'])) {
            $host = fn (?string $url) => $url === null ? null : self::hostOf($url);
            $changes['url'] = ['old' => $host($changes['url']['old']), 'new' => $host($changes['url']['new'])];

            // Same host, another path: still a change worth seeing, without the path.
            if ($changes['url']['old'] === $changes['url']['new']) {
                $changes['url']['new'] .= ' (new path)';
            }
        }

        if (array_key_exists('disabled_at', $changes)) {
            $changes['enabled'] = ['old' => $changes['disabled_at']['old'] === null, 'new' => $changes['disabled_at']['new'] === null];
            unset($changes['disabled_at']);
        }

        return $changes;
    }

    public function host(): string
    {
        return self::hostOf($this->url);
    }

    public static function hostOf(string $url): string
    {
        $host = (string) parse_url($url, PHP_URL_HOST);
        $port = parse_url($url, PHP_URL_PORT);

        return $port === null ? $host : "{$host}:{$port}";
    }

    public function isActive(): bool
    {
        return $this->disabled_at === null;
    }

    public function subscribesTo(WebhookEvent $event): bool
    {
        return in_array($event->value, $this->events, true);
    }

    /**
     * @return HasMany<WebhookDelivery, $this>
     */
    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class);
    }

    /**
     * @return HasOne<WebhookDelivery, $this>
     */
    public function latestDelivery(): HasOne
    {
        return $this->hasOne(WebhookDelivery::class)->latestOfMany();
    }
}
