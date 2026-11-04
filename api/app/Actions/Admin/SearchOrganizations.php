<?php

namespace App\Actions\Admin;

use App\Actions\Admin\Concerns\SearchesText;
use App\Models\Organization;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class SearchOrganizations
{
    use SearchesText;

    public const PER_PAGE = 25;

    /**
     * Every organization (platform admins only), newest first, matching $query in
     * the name. Subscriptions are loaded for the plan (Plans::for).
     *
     * @return LengthAwarePaginator<int, Organization>
     */
    public function handle(?string $query): LengthAwarePaginator
    {
        return Organization::query()
            ->when($query, fn (Builder $builder, string $query) => $builder->where('name', 'ilike', $this->containsPattern($query)))
            ->withCount('members')
            ->with('subscriptions')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();
    }
}
