<?php

namespace App\Http\Controllers\Api;

use App\Enums\AuditAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\AuditLog\ListAuditLogsRequest;
use App\Http\Resources\AuditLogResource;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * The current organization's audit log, newest first. Cursor pages ("Load more"):
 * no count query, and new entries don't shift the next page.
 */
class AuditLogController extends Controller
{
    public function index(ListAuditLogsRequest $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', AuditLog::class);

        $entries = AuditLog::query()
            ->with('actor:id,name')
            ->filter($request->filters())
            ->orderByDesc('id')
            ->cursorPaginate(AuditLog::PER_PAGE)
            ->withQueryString();

        return AuditLogResource::collection($entries);
    }

    /**
     * The action catalogue, for the filter.
     */
    public function actions(): JsonResponse
    {
        Gate::authorize('viewAny', AuditLog::class);

        return response()->json([
            'data' => array_map(fn (AuditAction $action) => [
                'value' => $action->value,
                'label' => $action->label(),
                'group' => $action->group(),
            ], AuditAction::cases()),
        ]);
    }
}
