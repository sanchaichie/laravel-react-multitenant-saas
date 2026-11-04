<?php

namespace App\Actions\Admin;

use App\Actions\Admin\Concerns\ActsOnUsers;
use App\Actions\Auth\IssueToken;
use App\Enums\PlatformAuditAction;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\ViewedBySupport;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StartImpersonation
{
    use ActsOnUsers;

    public function __construct(
        private readonly IssueToken $issueToken,
        private readonly RecordImpersonatedView $recordView,
        private readonly RecordPlatformAudit $recordAudit,
    ) {}

    /**
     * Open a read-only "view as user" session for a platform admin: a token for the
     * user that names the admin, expires after config('admin.impersonation_minutes')
     * and starts in the user's current organization (or their first one by name).
     * The user is emailed (without the reason), the organization's audit log says a
     * platform admin viewed it, and the platform log keeps the admin, IP and reason.
     *
     * @return array{token: string, expires_at: CarbonInterface}
     *
     * @throws ValidationException a platform admin (yourself included), suspended, or in no organization
     */
    public function handle(User $admin, User $user, string $reason): array
    {
        $minutes = (int) config('admin.impersonation_minutes');

        [$plainToken, $token, $locked] = DB::transaction(function () use ($admin, $user, $reason, $minutes) {
            // The organization row first, then the user (the lock order of member actions).
            // current_organization_id read directly: the admin's request has no token of the user's.
            $organization = ($user->current_organization_id === null ? null : $user->organizations()->find($user->current_organization_id))
                ?? $user->organizations()->orderBy('organizations.name')->orderBy('organizations.id')->first();
            if ($organization !== null) {
                Organization::query()->whereKey($organization->id)->sharedLock()->first();
            }

            $locked = $this->lockedTarget($user);

            if ($locked->isSuspended()) {
                throw ValidationException::withMessages(['user' => 'This user is suspended, so there is nothing to view.']);
            }

            // Re-checked after the lock: they may have just left it.
            if ($organization === null || ! $locked->isMemberOf($organization)) {
                throw ValidationException::withMessages(['user' => "This user isn't in any organization, so there is nothing to view."]);
            }

            [$plainToken, $token] = $this->issueToken->impersonation($locked, $admin, $organization, now()->addMinutes($minutes));

            $this->recordAudit->handle(PlatformAuditAction::ImpersonationStarted, RecordPlatformAudit::user($locked), [
                'reason' => $reason,
                'token_id' => $token->id,
                'expires_at' => $token->expires_at?->toIso8601String(),
            ]);
            $this->recordView->handle($token, $organization, $locked);

            return [$plainToken, $token, $locked];
        });

        $locked->notify(new ViewedBySupport(now()->toImmutable(), $minutes));

        return ['token' => $plainToken, 'expires_at' => $token->expires_at];
    }
}
