<?php

use App\Enums\DefaultRole;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;

// The create_roles_table migration on data in the old shape (role strings), both ways.

function rolesMigration(): object
{
    return require database_path('migrations/2026_10_30_090000_create_roles_table.php');
}

it('gives existing organizations the default roles and keeps everyone\'s role', function () {
    [$owner, $acme] = userInOrganization(DefaultRole::Owner, 'Acme');
    $admin = addMember($acme, DefaultRole::Admin);
    $member = addMember($acme);
    $custom = createRole($acme, 'Viewer');
    $viewer = addMember($acme, $custom);
    createInvitation($acme, 'ana@example.com', DefaultRole::Admin);

    rolesMigration()->down();

    // Back to strings; the custom role maps by what it allowed (nothing: member).
    expect(DB::table('organization_user')->orderBy('id')->pluck('role')->all())->toBe(['owner', 'admin', 'member', 'member'])
        ->and(DB::table('organization_invitations')->value('role'))->toBe('admin');

    // An organization made before roles existed.
    $old = DB::table('organizations')->insertGetId(['name' => 'Old', 'created_at' => now(), 'updated_at' => now()]);
    $oldOwner = User::factory()->create();
    DB::table('organization_user')->insert(['organization_id' => $old, 'user_id' => $oldOwner->id, 'role' => 'owner', 'created_at' => now(), 'updated_at' => now()]);

    rolesMigration()->up();

    $roleName = fn (Organization|int $organization, User $user) => DB::table('organization_user')
        ->join('roles', 'roles.id', '=', 'organization_user.role_id')
        ->where('organization_user.organization_id', $organization instanceof Organization ? $organization->id : $organization)
        ->where('user_id', $user->id)
        ->value('roles.name');

    expect(Role::withoutGlobalScopes()->where('organization_id', $old)->orderBy('id')->pluck('name')->all())->toBe(['Owner', 'Admin', 'Member'])
        ->and($roleName($acme, $owner))->toBe('Owner')
        ->and($roleName($acme, $admin))->toBe('Admin')
        ->and($roleName($acme, $member))->toBe('Member')
        ->and($roleName($acme, $viewer))->toBe('Member')
        ->and($roleName($old, $oldOwner))->toBe('Owner')
        ->and(Role::withoutGlobalScopes()->where('organization_id', $old)->where('is_owner', true)->sole()->grants()->count())->toBeGreaterThan(0);

    // With the migrations after it (audit_log.view for roles that manage members),
    // the defaults match today's DefaultRole permissions.
    (require database_path('migrations/2026_10_30_120001_grant_audit_log_view.php'))->up();
    $migrated = Role::withoutGlobalScopes()->where('organization_id', $old)->get()->keyBy('name');
    foreach (DefaultRole::cases() as $default) {
        expect($migrated[$default->label()]->permissions->all())->toBe($default->permissions());
    }
});

it('still deletes an organization with members and roles', function () {
    [, $acme] = userInOrganization(DefaultRole::Owner, 'Acme');
    addMember($acme, createRole($acme, 'Viewer'));
    createInvitation($acme, 'ana@example.com');

    $acme->delete();

    expect(Role::withoutGlobalScopes()->where('organization_id', $acme->id)->count())->toBe(0)
        ->and(DB::table('organization_user')->where('organization_id', $acme->id)->count())->toBe(0);
});
