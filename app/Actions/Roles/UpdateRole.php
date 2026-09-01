<?php

declare(strict_types=1);

namespace App\Actions\Roles;

use App\Exceptions\DomainException;
use App\Models\Role;
use App\Support\Concerns\RunsInTransaction;
use Illuminate\Support\Facades\Log;

class UpdateRole
{
    use RunsInTransaction;

    /**
     * @param  list<string>  $permissions
     */
    public function handle(Role $role, string $name, array $permissions): Role
    {
        return $this->transaction(function () use ($role, $name, $permissions): Role {
            // System roles keep their name; their permission set may still be
            // tuned (except Super Admin, which is guarded by the policy).
            if (! $role->isSystem()) {
                $role->name = $name;
                $role->save();
            }

            if ($role->isSuperAdmin()) {
                throw new DomainException('The Super Admin role cannot be modified.');
            }

            $role->syncPermissions($permissions);

            Log::info('role.updated', [
                'role' => $role->name,
                'permissions' => count($permissions),
                'by' => auth()->id(),
            ]);

            return $role->refresh();
        });
    }
}
