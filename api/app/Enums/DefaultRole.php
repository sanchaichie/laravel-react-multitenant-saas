<?php

namespace App\Enums;

/**
 * The roles every new organization starts with (CreateDefaultRoles). Afterwards
 * they are ordinary rows the organization can rename or change, except Owner.
 * Never use these to decide access: check the Role's permissions.
 */
enum DefaultRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Member = 'member';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public function isOwner(): bool
    {
        return $this === self::Owner;
    }

    /**
     * What the role starts with. The owner role stores none: it has every permission.
     *
     * @return list<Permission>
     */
    public function permissions(): array
    {
        return match ($this) {
            self::Owner => [],
            self::Admin => [Permission::TasksCreate, Permission::TasksManage, Permission::MembersInvite, Permission::MembersManage, Permission::AuditLogView],
            self::Member => [Permission::TasksCreate],
        };
    }
}
