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
        // One row per AI call (App\Ai\Clients\RecordingAiClient). Append-only: no updated_at.
        Schema::create('ai_usage', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->index()->constrained()->nullOnDelete();
            $table->string('feature', 64);
            $table->string('model', 128);
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->decimal('cost_usd', 12, 6)->nullable();
            $table->string('status', 16); // succeeded or failed
            $table->string('error_code', 64)->nullable();
            $table->timestamp('created_at')->nullable();

            // Per-organization totals over a period (plan 11's budget and usage page).
            $table->index(['organization_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_usage');
    }
};
