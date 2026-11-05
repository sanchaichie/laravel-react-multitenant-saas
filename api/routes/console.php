<?php

use App\Models\AuditLog;
use App\Models\OrganizationInvitation;
use App\Models\PlatformAuditLog;
use App\Models\WebhookDelivery;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Daily: delete invitations accepted or expired more than 30 days ago (OrganizationInvitation::prunable).
Schedule::command('model:prune', ['--model' => [OrganizationInvitation::class]])->daily();

// Daily: delete audit log entries (organization and platform) older than
// config('audit.retention_days'), and webhook deliveries older than
// config('webhooks.retention_days') (each model's prunable()).
Schedule::command('model:prune', ['--model' => [AuditLog::class, PlatformAuditLog::class, WebhookDelivery::class]])->daily();

// Daily: delete tokens that expired more than a day ago ("view as user" sessions; other tokens don't expire).
Schedule::command('sanctum:prune-expired', ['--hours' => 24])->daily();
