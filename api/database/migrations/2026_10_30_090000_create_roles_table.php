<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Replaces the fixed role strings on memberships and invitations with roles each
 * organization defines. Existing organizations get Owner, Admin and Member with
 * what the fixed roles allowed (a snapshot here, so later changes to
 * App\Enums\DefaultRole don't change what this migration does).
 */
return new class extends Migration
{
    /** @var array<string, array{name: string, is_owner: bool, permissions: list<string>}> */
    private const DEFAULTS = [
        'owner' => ['name' => 'Owner', 'is_owner' => true, 'permissions' => []],
        'admin' => ['name' => 'Admin', 'is_owner' => false, 'permissions' => ['tasks.create', 'tasks.manage', 'members.invite', 'members.manage']],
        'member' => ['name' => 'Member', 'is_owner' => false, 'permissions' => ['tasks.create']],
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name', 50);
            $table->boolean('is_owner')->default(false); // every permission; can't be edited or deleted
            $table->jsonb('permissions')->default('[]'); // App\Enums\Permission values
            $table->timestamps();

            // Target of the (organization_id, role_id) foreign keys below; also serves
            // listing an organization's roles.
            $table->unique(['organization_id', 'id']);
        });

        // Names are unique per organization ignoring case, and there's one owner role.
        DB::statement('CREATE UNIQUE INDEX roles_organization_id_lower_name_unique ON roles (organization_id, lower(name))');
        DB::statement('CREATE UNIQUE INDEX roles_one_owner_per_organization ON roles (organization_id) WHERE is_owner');

        foreach (['organization_user', 'organization_invitations'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->unsignedBigInteger('role_id')->nullable()->index();
            });
        }

        $now = now();
        foreach (DB::table('organizations')->pluck('id') as $organizationId) {
            foreach (self::DEFAULTS as $old => $role) {
                $roleId = DB::table('roles')->insertGetId([
                    'organization_id' => $organizationId,
                    'name' => $role['name'],
                    'is_owner' => $role['is_owner'],
                    'permissions' => json_encode($role['permissions']),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                foreach (['organization_user', 'organization_invitations'] as $table) {
                    DB::table($table)->where('organization_id', $organizationId)->where('role', $old)->update(['role_id' => $roleId]);
                }
            }
        }

        Schema::table('organization_user', function (Blueprint $table) {
            $table->unsignedBigInteger('role_id')->nullable(false)->change();
            $table->dropColumn('role');
        });
        Schema::table('organization_invitations', function (Blueprint $table) {
            $table->dropColumn('role');
        });

        // A membership or invitation can only point at a role of its own organization.
        // A role with members can't be deleted (DeleteRole checks first, with a message).
        // NO ACTION rather than RESTRICT: it's checked at the end of the statement, so
        // deleting an organization works whichever of its cascades (members, roles) runs
        // first. Invitations lose their role instead, which makes them unusable.
        DB::statement('ALTER TABLE organization_user ADD CONSTRAINT organization_user_role_foreign
            FOREIGN KEY (organization_id, role_id) REFERENCES roles (organization_id, id) ON DELETE NO ACTION');
        DB::statement('ALTER TABLE organization_invitations ADD CONSTRAINT organization_invitations_role_foreign
            FOREIGN KEY (organization_id, role_id) REFERENCES roles (organization_id, id) ON DELETE SET NULL (role_id)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Invitations whose role was deleted can't be accepted; the old model would
        // make them usable again as "member", so they go.
        DB::table('organization_invitations')->whereNull('role_id')->delete();

        foreach (['organization_user', 'organization_invitations'] as $table) {
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT {$table}_role_foreign");

            Schema::table($table, function (Blueprint $table) {
                $table->string('role')->nullable();
            });

            // Custom roles don't exist in the old model: map them by what they allow.
            DB::statement("UPDATE {$table} t SET role = CASE
                    WHEN r.is_owner THEN 'owner'
                    WHEN r.permissions @> '[\"members.manage\"]' THEN 'admin'
                    ELSE 'member'
                END
                FROM roles r WHERE r.id = t.role_id");
            DB::table($table)->whereNull('role')->update(['role' => 'member']);

            Schema::table($table, function (Blueprint $table) {
                $table->string('role')->nullable(false)->change();
                $table->dropColumn('role_id');
            });
        }

        Schema::dropIfExists('roles');
    }
};
