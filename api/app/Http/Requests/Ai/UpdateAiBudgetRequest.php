<?php

namespace App\Http\Requests\Ai;

use App\Models\AiUsage;
use Illuminate\Foundation\Http\FormRequest;

class UpdateAiBudgetRequest extends FormRequest
{
    // A role without the permission gets 403 before validation.
    public function authorize(): bool
    {
        return $this->user()->can('manageBudget', AiUsage::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Null goes back to the default budget; 0 turns AI off.
            'monthly_budget_usd' => ['present', 'nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:10000'],
        ];
    }

    public function messages(): array
    {
        return [
            'monthly_budget_usd.decimal' => 'Use at most 2 decimals (cents).',
            'monthly_budget_usd.max' => 'The budget can be at most $10,000 a month.',
        ];
    }

    /**
     * The new budget as a decimal string, or null for the default.
     */
    public function budget(): ?string
    {
        $amount = $this->validated('monthly_budget_usd');

        return $amount === null ? null : number_format((float) $amount, 2, '.', '');
    }
}
