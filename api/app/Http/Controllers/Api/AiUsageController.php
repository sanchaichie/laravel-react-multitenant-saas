<?php

namespace App\Http\Controllers\Api;

use App\Actions\Ai\AiUsageReport;
use App\Actions\Ai\UpdateAiBudget;
use App\Ai\AiBudget;
use App\Http\Controllers\Controller;
use App\Http\Requests\Ai\ListAiUsageRequest;
use App\Http\Requests\Ai\UpdateAiBudgetRequest;
use App\Models\AiUsage;
use App\Support\CurrentOrganization;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * AI usage and the monthly budget of the current organization.
 * CurrentOrganization is injected per action, not in the constructor: the router
 * reuses controller instances (tests, Octane), which would keep the first request's.
 */
class AiUsageController extends Controller
{
    public function index(ListAiUsageRequest $request, AiUsageReport $report, CurrentOrganization $currentOrganization): JsonResponse
    {
        Gate::authorize('viewAny', AiUsage::class);

        return response()->json([
            'data' => [
                ...$report->handle($currentOrganization->get(), $request->month()),
                'can' => ['manage_budget' => $request->user()->can('manageBudget', AiUsage::class)],
            ],
        ]);
    }

    // Authorized by UpdateAiBudgetRequest (ai_budget.manage), so a 403 comes before any 422.
    public function updateBudget(UpdateAiBudgetRequest $request, UpdateAiBudget $action, AiBudget $budget, CurrentOrganization $currentOrganization): JsonResponse
    {
        $organization = $action->handle($currentOrganization->get(), $request->budget());

        return response()->json(['data' => $budget->summary($organization)]);
    }
}
