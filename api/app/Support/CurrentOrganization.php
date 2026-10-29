<?php

namespace App\Support;

use App\Enums\OrganizationRole;
use App\Enums\Permission;
use App\Models\Organization;
use App\Models\User;
use Closure;
use Illuminate\Contracts\Auth\Factory as AuthFactory;

/**
 * The organization the current request (or job) acts in.
 *
 * Bound as a scoped singleton: resolved at most once per request or job. For an
 * authenticated user it is their stored current organization, but only while
 * they are still a member of it. Never taken from client input.
 */
class CurrentOrganization
{
    private bool $resolved = false;

    private ?Organization $organization = null;

    private ?OrganizationRole $role = null;

    public function __construct(private readonly AuthFactory $auth) {}

    public function get(): ?Organization
    {
        $this->resolve();

        return $this->organization;
    }

    public function id(): ?int
    {
        return $this->get()?->getKey();
    }

    public function role(): ?OrganizationRole
    {
        $this->resolve();

        return $this->role;
    }

    /**
     * Whether the user's role here grants the permission (false without one).
     */
    public function can(Permission $permission): bool
    {
        return $this->role()?->can($permission) ?? false;
    }

    /**
     * Run code acting in an organization, then go back to what was current before
     * (even if it throws). Use this in jobs and listeners: a job run inline (the
     * sync queue, or called during a request) shares the request's instance, and a
     * bare set() would leave the rest of the request acting in that organization.
     * The caller is responsible for having checked access.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function run(Organization $organization, Closure $callback, ?OrganizationRole $role = null): mixed
    {
        $previous = [$this->resolved, $this->organization, $this->role];
        $this->set($organization, $role);

        try {
            return $callback();
        } finally {
            [$this->resolved, $this->organization, $this->role] = $previous;
        }
    }

    /**
     * Act in an organization explicitly, e.g. in a test. Prefer run(), which
     * restores the previous state. The caller is responsible for having checked access.
     */
    public function set(Organization $organization, ?OrganizationRole $role = null): void
    {
        $this->organization = $organization;
        $this->role = $role;
        $this->resolved = true;
    }

    /**
     * Drop the cached value, e.g. after the user switched organizations.
     */
    public function forget(): void
    {
        $this->organization = null;
        $this->role = null;
        $this->resolved = false;
    }

    private function resolve(): void
    {
        if ($this->resolved) {
            return;
        }

        $user = $this->auth->guard()->user();

        // Not authenticated (yet): don't cache, so a call after auth still resolves.
        if (! $user instanceof User) {
            return;
        }

        $this->resolved = true;
        $this->organization = $user->activeOrganization();
        $this->role = $this->organization?->membership?->role;
    }
}
