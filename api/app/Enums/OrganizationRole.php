<?php

namespace App\Enums;

/**
 * A member's role in one organization. Plan 08 replaces it with custom roles and
 * permissions; until then nothing checks it.
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
}
