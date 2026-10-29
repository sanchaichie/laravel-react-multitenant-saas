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
        Schema::create('organizations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('organization_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role'); // App\Enums\OrganizationRole (a roles table from plan 08)
            $table->timestamps();

            // Also serves lookups by organization (members); user_id gets its own index.
            $table->unique(['organization_id', 'user_id']);
            $table->index('user_id');
        });

        Schema::table('users', function (Blueprint $table) {
            // Only a pointer: access is always re-checked against organization_user.
            // Indexed, so deleting an organization finds the users to null out quickly.
            $table->foreignId('current_organization_id')
                ->nullable()
                ->index()
                ->constrained('organizations')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('current_organization_id');
        });

        Schema::dropIfExists('organization_user');
        Schema::dropIfExists('organizations');
    }
};
