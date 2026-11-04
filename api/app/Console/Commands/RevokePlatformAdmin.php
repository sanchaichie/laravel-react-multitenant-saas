<?php

namespace App\Console\Commands;

use App\Actions\Admin\RecordPlatformAudit;
use App\Enums\PlatformAuditAction;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RevokePlatformAdmin extends Command
{
    protected $signature = 'admin:revoke {email : The user\'s email address}';

    protected $description = 'Stop a user being a platform admin (from their next request)';

    public function handle(RecordPlatformAudit $recordAudit): int
    {
        return DB::transaction(function () use ($recordAudit) {
            $user = User::query()->where('email', mb_strtolower((string) $this->argument('email')))->lockForUpdate()->first();

            if ($user === null) {
                $this->error('No user with that email address.');

                return self::FAILURE;
            }

            if (! $user->isPlatformAdmin()) {
                $this->info("{$user->email} isn't a platform admin.");

                return self::SUCCESS;
            }

            $user->forceFill(['is_platform_admin' => false])->save();
            $recordAudit->handle(PlatformAuditAction::AdminRevoked, RecordPlatformAudit::user($user));

            $this->info("{$user->email} is no longer a platform admin.");

            return self::SUCCESS;
        });
    }
}
