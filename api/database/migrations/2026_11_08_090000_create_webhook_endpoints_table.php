<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An organization's outgoing webhook endpoints (App\Models\WebhookEndpoint).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_endpoints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('url', 2048);
            $table->string('description', 200)->nullable();
            $table->jsonb('events'); // list of App\Enums\WebhookEvent values
            $table->text('secret'); // encrypted
            $table->timestamp('disabled_at')->nullable();
            $table->string('disabled_reason', 16)->nullable(); // manual, failing
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->timestamps();

            // Target of webhook_deliveries' composite key; organization_id leads, for the list.
            $table->unique(['organization_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_endpoints');
    }
};
