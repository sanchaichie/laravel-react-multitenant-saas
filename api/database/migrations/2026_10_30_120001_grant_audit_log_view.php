<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * New organizations' Admin role gets audit_log.view (App\Enums\DefaultRole). Existing
 * roles get it when they can already manage members, decided by permission, never by
 * the role's name. Running it again changes nothing.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::statement(<<<'SQL'
            UPDATE roles SET permissions = permissions || '["audit_log.view"]'::jsonb
            WHERE NOT is_owner
              AND permissions @> '["members.manage"]'::jsonb
              AND NOT permissions @> '["audit_log.view"]'::jsonb
            SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement("UPDATE roles SET permissions = permissions - 'audit_log.view' WHERE permissions @> '[\"audit_log.view\"]'::jsonb");
    }
};
