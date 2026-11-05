<?php

namespace App\Http\Requests\Webhooks;

use App\Enums\WebhookEvent;
use App\Models\WebhookEndpoint;
use App\Webhooks\UnsafeWebhookUrl;
use App\Webhooks\WebhookUrl;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Creating (POST, every field but description required) and editing (PATCH, only
 * the fields sent) a webhook. The URL is checked for SSRF here (WebhookUrl), and
 * again before every delivery.
 */
class WebhookEndpointRequest extends FormRequest
{
    // A role without webhooks.manage gets 403 before validation.
    public function authorize(): bool
    {
        $endpoint = $this->route('webhook');

        return $endpoint instanceof WebhookEndpoint
            ? $this->user()->can('update', $endpoint)
            : $this->user()->can('create', WebhookEndpoint::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'url' => [$required, 'string', 'max:2048', function (string $attribute, mixed $value, Closure $fail) {
                try {
                    app(WebhookUrl::class)->target($value);
                } catch (UnsafeWebhookUrl $exception) {
                    $fail($exception->getMessage());
                }
            }],
            'description' => ['sometimes', 'nullable', 'string', 'max:200'],
            'events' => [$required, 'array', 'min:1', 'max:'.count(WebhookEvent::cases())],
            'events.*' => ['required', 'string', 'distinct', Rule::enum(WebhookEvent::class)],
            'enabled' => $this->isMethod('POST') ? ['prohibited'] : ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'events.required' => 'Choose at least one event.',
            'events.min' => 'Choose at least one event.',
        ];
    }

    /**
     * The validated fields, events as a plain list, a blank description as null.
     *
     * @return array<string, mixed>
     */
    public function endpointAttributes(): array
    {
        $attributes = $this->validated();

        if (isset($attributes['events'])) {
            $attributes['events'] = array_values($attributes['events']);
        }

        if (array_key_exists('description', $attributes)) {
            $attributes['description'] = blank($attributes['description']) ? null : trim($attributes['description']);
        }

        if (array_key_exists('enabled', $attributes)) {
            $attributes['enabled'] = (bool) $attributes['enabled'];
        }

        return $attributes;
    }
}
