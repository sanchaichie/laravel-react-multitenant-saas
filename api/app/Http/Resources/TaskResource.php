<?php

namespace App\Http\Resources;

use App\Models\Task;
use App\Support\Impersonation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

/**
 * `can` and `is_overdue` come from the server, so the app never repeats the rules
 * or compares dates itself.
 *
 * @mixin Task
 */
class TaskResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $gate = Gate::forUser($request->user());
        // A platform admin viewing as the user sees titles (what the list shows), not content.
        $hidden = app(Impersonation::class)->active();

        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $hidden ? null : $this->description,
            'content_hidden' => $hidden,
            'status' => $this->status->value,
            'priority' => $this->priority->value,
            'due_date' => $this->due_date?->toDateString(),
            'is_overdue' => $this->isOverdue(),
            'assignee' => $this->whenLoaded('assignee', fn () => $this->assignee ? ['id' => $this->assignee->id, 'name' => $this->assignee->name] : null),
            'creator' => $this->whenLoaded('creator', fn () => $this->creator ? ['id' => $this->creator->id, 'name' => $this->creator->name] : null),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'can' => [
                'update' => $gate->allows('update', $this->resource),
                'delete' => $gate->allows('delete', $this->resource),
                // The route also needs a verified email: no button that would only get a 403.
                'write_with_ai' => (bool) $request->user()?->hasVerifiedEmail() && $gate->allows('writeWithAi', $this->resource),
                // Reading your history needs only this; sending also needs a verified email (the app says so).
                'chat' => $gate->allows('chat', $this->resource),
            ],
        ];
    }
}
