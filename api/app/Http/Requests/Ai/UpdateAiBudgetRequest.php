<?php

namespace App\Http\Requests\Ai;

use App\Ai\AiBudget;
use App\Billing\Plans;
use App\Models\AiUsage;
use App\Support\CurrentOrganization;
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
            // Null goes back to the plan's budget; 0 turns AI off. The plan's budget is the
            // most an organization can give itself (more needs a better plan, or credits).
            'monthly_budget_usd' => ['present', 'nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:'.$this->planLimit()],
        ];
    }

    public function messages(): array
    {
        return [
            'monthly_budget_usd.decimal' => 'Use at most 2 decimals (cents).',
            'monthly_budget_usd.max' => $this->maxMessage(),
        ];
    }

    private function planLimit(): float
    {
        return app(AiBudget::class)->planLimit(app(CurrentOrganization::class)->get());
    }

    private function maxMessage(): string
    {
        $plans = app(Plans::class);
        $plan = $plans->for(app(CurrentOrganization::class)->get());
        $limit = '$'.number_format($plan->aiMonthlyBudgetUsd, 2);

        return $plan->isPaid
            ? "The {$plan->name} plan allows at most {$limit} a month. Buy AI credits for more."
            : "The {$plan->name} plan allows at most {$limit} a month. Upgrade in Billing for more.";
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
