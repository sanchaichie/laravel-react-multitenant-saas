<?php

namespace App\Actions\Admin;

use App\Enums\PlatformAuditAction;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

class ShowUser
{
    public function __construct(private readonly RecordPlatformAudit $recordAudit) {}

    /**
     * One user for a platform admin: their organizations with the role in each, how
     * many devices are signed in and when one was last used. Recorded in the
     * platform log each time it's opened (unless $record is false: the answer to an action).
     */
    public function handle(User $user, bool $record = true): User
    {
        $user->load(['organizations' => fn ($query) => $query->orderBy('organizations.name')->orderBy('organizations.id')]);
        // Each membership's role in one query (eager loading can't reach through the pivot).
        (new EloquentCollection($user->organizations->pluck('membership')))->load('role');

        // Their own devices: support sessions don't count, nor make them "active".
        $devices = ['tokens' => fn ($query) => $query->whereNull('impersonator_id')];
        $user->loadCount($devices);
        $user->loadMax($devices, 'last_used_at');

        if ($record) {
            $this->recordAudit->handle(PlatformAuditAction::UserViewed, RecordPlatformAudit::user($user));
        }

        return $user;
    }
}
