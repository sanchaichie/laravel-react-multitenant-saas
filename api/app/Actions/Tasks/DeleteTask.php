<?php

namespace App\Actions\Tasks;

use App\Models\Task;
use Illuminate\Support\Facades\DB;

class DeleteTask
{
    /**
     * In a transaction, so the audit entry (Auditable) commits with the delete.
     */
    public function handle(Task $task): void
    {
        DB::transaction(fn () => $task->delete());
    }
}
