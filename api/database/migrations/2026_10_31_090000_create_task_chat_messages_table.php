<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One user's private AI chat about one task. Composite keys keep each message in its
 * row's organization and clean up by themselves: deleting the task, or the membership
 * (removed, or left), deletes those messages.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('task_chat_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('task_id');
            $table->unsignedBigInteger('user_id');
            $table->string('role', 16);
            $table->text('content');
            $table->string('model', 128)->nullable();
            $table->string('status', 16);
            $table->timestamps();

            $table->foreign(['organization_id', 'task_id'])->references(['organization_id', 'id'])->on('tasks')->cascadeOnDelete();
            $table->foreign(['organization_id', 'user_id'])->references(['organization_id', 'user_id'])->on('organization_user')->cascadeOnDelete();
            // One conversation, newest last; task_id leads, so the task's cascade uses it too.
            $table->index(['task_id', 'user_id', 'id']);
            // For the membership's cascade.
            $table->index(['organization_id', 'user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('task_chat_messages');
    }
};
