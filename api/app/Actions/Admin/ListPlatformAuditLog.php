<?php

namespace App\Actions\Admin;

use App\Models\PlatformAuditLog;
use Illuminate\Contracts\Pagination\CursorPaginator;

class ListPlatformAuditLog
{
    /**
     * The platform log, newest first, in cursor pages ("Load more").
     *
     * @return CursorPaginator<int, PlatformAuditLog>
     */
    public function handle(): CursorPaginator
    {
        return PlatformAuditLog::query()
            ->with('actor:id,name,email')
            ->orderByDesc('id')
            ->cursorPaginate(PlatformAuditLog::PER_PAGE);
    }
}
