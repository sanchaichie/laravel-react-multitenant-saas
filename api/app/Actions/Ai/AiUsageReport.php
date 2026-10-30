<?php

namespace App\Actions\Ai;

use App\Ai\AiBudget;
use App\Models\AiUsage;
use App\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * One month of the current organization's AI usage: totals and breakdowns by
 * feature, person and day, plus the budget. Bounded by the month, so it never
 * reads more than that month's rows.
 */
class AiUsageReport
{
    public function __construct(private readonly AiBudget $budget) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(Organization $organization, CarbonImmutable $month): array
    {
        $from = $month->startOfMonth();
        // Scoped to the current organization by BelongsToOrganization. Columns are
        // qualified throughout: the by-person breakdown joins users.
        $rows = fn (): Builder => AiUsage::query()
            ->where('ai_usage.created_at', '>=', $from)
            ->where('ai_usage.created_at', '<', $from->addMonth());

        $aggregates = [
            DB::raw('count(*) as calls'),
            DB::raw("count(*) filter (where ai_usage.status = 'failed') as failed"),
            DB::raw('coalesce(sum(ai_usage.input_tokens), 0) as input_tokens'),
            DB::raw('coalesce(sum(ai_usage.output_tokens), 0) as output_tokens'),
            DB::raw('coalesce(sum(ai_usage.cost_usd), 0) as cost_usd'),
        ];

        $format = fn (object $row): array => [
            'calls' => (int) $row->calls,
            'failed' => (int) $row->failed,
            'input_tokens' => (int) $row->input_tokens,
            'output_tokens' => (int) $row->output_tokens,
            'cost_usd' => round((float) $row->cost_usd, 6),
        ];

        $byUser = $rows()->toBase()
            ->leftJoin('users', 'users.id', '=', 'ai_usage.user_id')
            ->groupBy('ai_usage.user_id', 'users.name')
            ->orderByDesc('cost_usd')
            ->orderBy('users.name')
            ->orderBy('ai_usage.user_id')
            ->get(['ai_usage.user_id', 'users.name', ...$aggregates]);

        return [
            'month' => $from->format('Y-m'),
            'totals' => $format($rows()->toBase()->first($aggregates)),
            'by_feature' => $rows()->toBase()->groupBy('ai_usage.feature')->orderBy('ai_usage.feature')
                ->get(['ai_usage.feature', ...$aggregates])
                ->map(fn (object $row) => ['feature' => $row->feature, ...$format($row)])->all(),
            // No user: work started without a person, or by one whose account is gone.
            'by_user' => $byUser->map(fn (object $row) => [
                'user' => $row->user_id !== null && $row->name !== null ? ['id' => $row->user_id, 'name' => $row->name] : null,
                ...$format($row),
            ])->all(),
            'by_day' => $rows()->toBase()->groupBy(DB::raw("date_trunc('day', ai_usage.created_at)"))->orderBy('day')
                ->get([DB::raw("to_char(date_trunc('day', ai_usage.created_at), 'YYYY-MM-DD') as day"), ...$aggregates])
                ->map(fn (object $row) => ['day' => $row->day, ...$format($row)])->all(),
            // The budget always describes the current month, whichever month is shown.
            'budget' => $this->budget->summary($organization),
        ];
    }
}
