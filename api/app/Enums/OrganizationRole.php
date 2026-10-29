<?php

namespace App\Enums;

/**
 * A member's role in one organization. Plan 08 replaces it with custom roles;
 * policies already check permissions (grants()), not these names.
 */
enum OrganizationRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Member = 'member';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    /**
     * @return list<Permission>
     */
    public function grants(): array
    {
        return match ($this) {
            self::Owner => Permission::cases(),
            self::Admin => [Permission::MembersInvite, Permission::MembersManage, Permission::TasksManage],
            self::Member => [],
        };
    }

    public function can(Permission $permission): bool
    {
        return in_array($permission, $this->grants(), true);
    }

    /**
     * Whether this role allows everything $other does. You may only act on, or
     * give, roles you include: an admin can't touch owners or make one.
     */
    public function includes(self $other): bool
    {
        return array_diff(
            array_map(fn (Permission $p) => $p->value, $other->grants()),
            array_map(fn (Permission $p) => $p->value, $this->grants()),
        ) === [];
    }
}
