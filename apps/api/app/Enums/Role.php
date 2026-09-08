<?php

namespace App\Enums;

enum Role: string
{
    case PlatformAdmin = 'platform_admin';
    case OrganizationOwner = 'organization_owner';
    case Buyer = 'buyer';
    case Viewer = 'viewer';

    /**
     * @return list<string>
     */
    public static function organizationValues(): array
    {
        return [
            self::OrganizationOwner->value,
            self::Buyer->value,
            self::Viewer->value,
        ];
    }
}
