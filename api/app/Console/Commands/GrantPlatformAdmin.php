<?php

namespace App\Console\Commands;

use App\Actions\Admin\RecordPlatformAudit;
use App\Enums\PlatformAuditAction;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * The only way to make someone a platform admin: no API request can set the flag.
 * They need two-factor on first, set up by themselves (turning it off takes the
 * flag away again, DisableTwoFactor::clear).
 */
class GrantPlatformAdmin extends Command
{
    protected $signature = 'admin:grant {email : The user\'s email address}';

    protected $description = 'Make a user a platform admin (they need two-factor turned on first)';

    public function handle(RecordPlatformAudit $recordAudit): int
    {
        return DB::transaction(function () use ($recordAudit) {
            $user = User::query()->where('email', mb_strtolower((string) $this->argument('email')))->lockForUpdate()->first();

            if ($user === null) {
                $this->error('No user with that email address.');

                return self::FAILURE;
            }

            if (! $user->hasTwoFactorEnabled()) {
                $this->error('They need two-factor authentication turned on first (Account → Two-factor authentication).');

                return self::FAILURE;
            }

            if ($user->isPlatformAdmin()) {
                $this->info("{$user->email} is already a platform admin.");

                return self::SUCCESS;
            }

            $user->forceFill(['is_platform_admin' => true])->save();
            $recordAudit->handle(PlatformAuditAction::AdminGranted, RecordPlatformAudit::user($user));

            $this->info("{$user->email} is now a platform admin.");

            return self::SUCCESS;
        });
    }
}
