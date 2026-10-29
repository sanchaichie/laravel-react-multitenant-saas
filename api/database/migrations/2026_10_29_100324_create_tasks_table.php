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
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('status')->default('todo'); // App\Enums\TaskStatus
            $table->string('priority')->default('medium'); // App\Enums\TaskPriority
            $table->date('due_date')->nullable();
            // A member of the same organization; leaving unassigns (RemoveMember).
            // Both user columns are indexed on their own: deleting a user nulls them
            // out across every organization.
            $table->foreignId('assignee_id')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // The list's filters and sorts, always within one organization.
            $table->index(['organization_id', 'status']);
            $table->index(['organization_id', 'assignee_id']);
            $table->index(['organization_id', 'due_date']);
            $table->index(['organization_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};
