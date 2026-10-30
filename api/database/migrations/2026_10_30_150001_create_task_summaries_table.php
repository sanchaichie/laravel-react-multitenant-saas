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
        Schema::create('task_summaries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->index()->constrained()->cascadeOnDelete();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->string('status', 16); // App\Enums\TaskSummaryStatus
            $table->text('summary')->nullable();
            $table->jsonb('key_points')->nullable();
            $table->string('model', 128)->nullable();
            $table->string('error', 64)->nullable(); // an internal code; the resource shows a generic message
            // sha256 of the task's title and description as sent to the model.
            $table->char('source_hash', 64)->nullable();
            $table->foreignId('requested_by')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            // task_id leads: the latest and approved summary of a task, and cascading deletes.
            $table->index(['task_id', 'status']);
        });

        // At most one approved and one pending summary per task, even if a future
        // code path skips the actions' locks.
        DB::statement("create unique index task_summaries_one_approved on task_summaries (task_id) where status = 'approved'");
        DB::statement("create unique index task_summaries_one_pending on task_summaries (task_id) where status = 'pending'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('task_summaries');
    }
};
