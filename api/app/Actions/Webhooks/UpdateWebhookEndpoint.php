<?php

namespace App\Actions\Webhooks;

use App\Models\WebhookEndpoint;
use Illuminate\Support\Facades\DB;

class UpdateWebhookEndpoint
{
    /**
     * @param  array{url?: string, description?: string|null, events?: list<string>, enabled?: bool}  $attributes  validated (only the fields sent)
     */
    public function handle(WebhookEndpoint $endpoint, array $attributes): WebhookEndpoint
    {
        return DB::transaction(function () use ($endpoint, $attributes) {
            $endpoint = WebhookEndpoint::query()->whereKey($endpoint->id)->lock('for no key update')->firstOrFail();
            $endpoint->fill(array_diff_key($attributes, ['enabled' => true]));

            if (array_key_exists('enabled', $attributes)) {
                if ($attributes['enabled'] && ! $endpoint->isActive()) {
                    // A fresh start: the failures that disabled it are behind it.
                    $endpoint->disabled_at = null;
                    $endpoint->disabled_reason = null;
                    $endpoint->consecutive_failures = 0;
                } elseif (! $attributes['enabled'] && $endpoint->isActive()) {
                    $endpoint->disabled_at = now();
                    $endpoint->disabled_reason = WebhookEndpoint::DISABLED_MANUALLY;
                }
            }

            $endpoint->save();

            return $endpoint;
        });
    }
}
