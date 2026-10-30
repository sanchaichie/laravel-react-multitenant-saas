<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Append-only (App\Models\AuditLog): no updated_at.
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 64); // App\Enums\AuditAction
            $table->string('subject_type', 64)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable(); // no foreign key: the subject may be deleted
            $table->string('subject_label')->nullable(); // its name when it happened
            $table->jsonb('changes')->nullable(); // {field: {old, new}}
            $table->jsonb('metadata')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at');

            // The list is ORDER BY id DESC, unfiltered or by action or actor.
            $table->index(['organization_id', 'id']);
            $table->index(['organization_id', 'action', 'id']);
            $table->index(['organization_id', 'actor_id', 'id']);
            // The date filter, and pruning across organizations.
            $table->index(['organization_id', 'created_at']);
            $table->index('created_at');
            // Deleting a user sets their entries' actor_id to null.
            $table->index('actor_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
