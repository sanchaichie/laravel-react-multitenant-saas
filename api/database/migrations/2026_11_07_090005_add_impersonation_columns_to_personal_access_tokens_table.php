<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A platform admin's read-only "view as user" session is a token for the user that
 * names the admin (impersonator_id), with the organization it's looking at (session
 * state, not a tenant column: never a filter) and the organizations already told.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->foreignId('impersonator_id')->nullable()->index()->constrained('users')->cascadeOnDelete();
            // A deleted organization leaves the session with none, instead of deleting it.
            $table->foreignId('organization_id')->nullable()->index()->constrained()->nullOnDelete();
            $table->jsonb('viewed_organization_ids')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->dropConstrainedForeignId('impersonator_id');
            $table->dropConstrainedForeignId('organization_id');
            $table->dropColumn('viewed_organization_ids');
        });
    }
};
