<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One event sent (or being sent) to one endpoint (App\Models\WebhookDelivery). The
 * composite key keeps it in its endpoint's organization, and deleting the endpoint
 * deletes its deliveries.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('webhook_endpoint_id');
            $table->uuid('event_id'); // the same on a resend, so receivers can dedupe
            $table->string('event', 32);
            $table->jsonb('payload');
            $table->string('status', 16); // App\Enums\WebhookDeliveryStatus
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->text('response_body')->nullable();
            $table->string('error', 255)->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();

            $table->foreign(['organization_id', 'webhook_endpoint_id'])
                ->references(['organization_id', 'id'])->on('webhook_endpoints')->cascadeOnDelete();
            // Both cascades (organization, endpoint), an endpoint's deliveries newest first
            // (cursor pages) and its latest one.
            $table->index(['organization_id', 'webhook_endpoint_id', 'id']);
            // Pruning.
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_deliveries');
    }
};
