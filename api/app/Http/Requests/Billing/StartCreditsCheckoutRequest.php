<?php

namespace App\Http\Requests\Billing;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StartCreditsCheckoutRequest extends FormRequest
{
    // A role without the permission gets 403 before validation.
    public function authorize(): bool
    {
        return $this->user()->can('manageBilling');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // A pack key from config/billing.php, never a price id or an amount.
            'pack' => ['required', 'string', Rule::in(array_keys(config('billing.credit_packs')))],
        ];
    }
}
