<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A summary is always in its task's organization, enforced by the database
     * (like roles in plan 08): (organization_id, task_id) points at tasks
     * (organization_id, id), so a mismatch can't be stored even if code skips the scope.
     */
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->unique(['organization_id', 'id']);
        });

        Schema::table('task_summaries', function (Blueprint $table) {
            $table->dropForeign(['task_id']);
            $table->foreign(['organization_id', 'task_id'])->references(['organization_id', 'id'])->on('tasks')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('task_summaries', function (Blueprint $table) {
            $table->dropForeign(['organization_id', 'task_id']);
            $table->foreign('task_id')->references('id')->on('tasks')->cascadeOnDelete();
        });

        Schema::table('tasks', function (Blueprint $table) {
            $table->dropUnique(['organization_id', 'id']);
        });
    }
};
