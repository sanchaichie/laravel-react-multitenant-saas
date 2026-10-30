<?php

use App\Models\AuditLog;
use App\Models\OrganizationInvitation;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Daily: delete invitations accepted or expired more than 30 days ago (OrganizationInvitation::prunable).
Schedule::command('model:prune', ['--model' => [OrganizationInvitation::class]])->daily();

// Daily: delete audit log entries older than config('audit.retention_days') (AuditLog::prunable).
Schedule::command('model:prune', ['--model' => [AuditLog::class]])->daily();
