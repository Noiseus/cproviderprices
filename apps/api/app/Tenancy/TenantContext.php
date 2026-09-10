<?php

namespace App\Tenancy;

use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;

final readonly class TenantContext
{
    public function __construct(
        public User $user,
        public Organization $organization,
        public OrganizationMembership $membership,
    ) {}
}
