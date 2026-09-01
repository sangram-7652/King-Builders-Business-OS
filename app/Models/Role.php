<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RoleName;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * App-level Role model so we can attach a policy via auto-discovery and add
 * domain helpers. Wired in via config/permission.php (`models.role`).
 */
class Role extends SpatieRole
{
    /** A system role is one defined in the RoleName enum — protected from deletion/rename. */
    public function isSystem(): bool
    {
        return in_array($this->name, RoleName::names(), true);
    }

    public function isSuperAdmin(): bool
    {
        return $this->name === RoleName::SuperAdmin->value;
    }
}
