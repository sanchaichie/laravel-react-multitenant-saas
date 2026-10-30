<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * New organizations' roles get the AI permissions from App\Enums\DefaultRole. Existing
 * roles get them by what they can already do, never by the role's name: anyone who can
 * create or manage tasks (and so ask for summaries today) keeps asking (ai.use), and anyone who can manage members
 * sees AI usage (ai_usage.view). Nobody gets ai_budget.manage: only the owner has it.
 * Running it again changes nothing.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::statement(<<<'SQL'
            UPDATE roles SET permissions = permissions || '["ai.use"]'::jsonb
            WHERE NOT is_owner
              AND (permissions @> '["tasks.create"]'::jsonb OR permissions @> '["tasks.manage"]'::jsonb)
              AND NOT permissions @> '["ai.use"]'::jsonb
            SQL);

        DB::statement(<<<'SQL'
            UPDATE roles SET permissions = permissions || '["ai_usage.view"]'::jsonb
            WHERE NOT is_owner
              AND permissions @> '["members.manage"]'::jsonb
              AND NOT permissions @> '["ai_usage.view"]'::jsonb
            SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (['ai.use', 'ai_usage.view', 'ai_budget.manage'] as $permission) {
            DB::statement('UPDATE roles SET permissions = permissions - ? WHERE permissions @> ?::jsonb', [$permission, json_encode([$permission])]);
        }
    }
};
