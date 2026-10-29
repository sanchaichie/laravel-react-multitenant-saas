<?php

namespace App\Enums;

/**
 * What a role allows in an organization. Policies check these, never role names.
 * Until plan 08 the default roles map to them (OrganizationRole::grants()); custom
 * roles then store their own set.
 */
enum Permission: string
{
    case MembersInvite = 'members.invite';
    case MembersManage = 'members.manage';
    case OrganizationManage = 'organization.manage';
}
