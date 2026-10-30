<?php

namespace App\Http\Resources;

use App\Models\TaskChatMessage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin TaskChatMessage
 */
class TaskChatMessageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'role' => $this->role->value,
            'content' => $this->content,
            'status' => $this->status->value,
            'created_at' => $this->created_at,
        ];
    }
}
