<?php

namespace App\Models\Concerns;

use App\Exceptions\NoCurrentOrganization;
use App\Models\Organization;
use App\Models\Scopes\OrganizationScope;
use App\Support\CurrentOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * For tenant-owned models (their table has an organization_id column).
 *
 * - Queries only see rows of the current organization (none if there is none).
 * - organization_id is always set from the current organization on create,
 *   overwriting any value passed in, and can't be changed afterwards.
 */
trait BelongsToOrganization
{
    public static function bootBelongsToOrganization(): void
    {
        static::addGlobalScope(new OrganizationScope);

        static::creating(function (Model $model) {
            $organizationId = app(CurrentOrganization::class)->id();

            if ($organizationId === null) {
                throw NoCurrentOrganization::forCreating($model::class);
            }

            $model->setAttribute('organization_id', $organizationId);
        });

        static::updating(function (Model $model) {
            if ($model->isDirty('organization_id')) {
                throw new LogicException('A record cannot be moved to another organization.');
            }
        });
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
