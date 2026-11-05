<?php

namespace App\Actions\Webhooks;

use App\Actions\Organizations\Concerns\EnsuresAnOwnerRemains;
use App\Models\WebhookEndpoint;
use App\Support\CurrentOrganization;
use App\Webhooks\WebhookSigner;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateWebhookEndpoint
{
    use EnsuresAnOwnerRemains;

    public function __construct(private readonly CurrentOrganization $currentOrganization) {}

    /**
     * @param  array{url: string, description?: string|null, events: list<string>}  $attributes  validated
     * @return array{WebhookEndpoint, string} the endpoint and its secret (shown once)
     */
    public function handle(array $attributes): array
    {
        return DB::transaction(function () use ($attributes) {
            // On the organization row, so two requests at once can't both pass the limit.
            static::lockOrganizationRow($this->currentOrganization->id());

            $max = (int) config('webhooks.max_endpoints');
            if (WebhookEndpoint::query()->count() >= $max) {
                throw ValidationException::withMessages(['url' => "An organization can have at most {$max} webhooks."]);
            }

            $secret = WebhookSigner::generateSecret();
            $endpoint = new WebhookEndpoint($attributes);
            $endpoint->secret = $secret;
            $endpoint->save();

            return [$endpoint, $secret];
        });
    }
}
