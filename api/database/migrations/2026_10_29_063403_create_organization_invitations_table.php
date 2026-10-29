<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('organization_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('email'); // lowercased
            $table->string('role'); // App\Enums\OrganizationRole (a roles table from plan 08)
            // sha256 of the token in the email link; the token itself is never stored.
            $table->string('token_hash', 64)->unique();
            // Indexed: deleting a user nulls these out.
            $table->foreignId('invited_by')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();

            // An organization's invitations, and re-inviting one address.
            $table->index(['organization_id', 'email']);
        });

        // One pending invitation per address and organization, even when two are sent
        // at once (InviteMember's check-then-insert can't promise that on its own).
        // Partial, so accepted invitations don't count; the schema builder can't express that.
        DB::statement('CREATE UNIQUE INDEX organization_invitations_pending_unique ON organization_invitations (organization_id, email) WHERE accepted_at IS NULL');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('organization_invitations');
    }
};
