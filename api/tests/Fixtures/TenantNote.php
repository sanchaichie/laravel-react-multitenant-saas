<?php

namespace Tests\Fixtures;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

/**
 * Test-only tenant-owned model for exercising BelongsToOrganization.
 * Its table is created inside the test, not by a migration.
 */
class TenantNote extends Model
{
    use BelongsToOrganization;

    protected $table = 'tenant_notes';

    // Deliberately includes organization_id, to prove the trait overrides it anyway.
    protected $fillable = ['body', 'organization_id'];
}
