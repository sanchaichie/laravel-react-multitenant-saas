<?php

namespace App\Http\Requests\Billing;

use App\Billing\Plans;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StartCheckoutRequest extends FormRequest
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
            // A plan key from config/billing.php, never a price id or an amount.
            'plan' => ['required', 'string', Rule::in(app(Plans::class)->paidKeys())],
        ];
    }
}
