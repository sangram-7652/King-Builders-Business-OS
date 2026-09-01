<?php

declare(strict_types=1);

namespace App\Actions\Roles;

use App\Models\Role;
use App\Support\Concerns\RunsInTransaction;
use Illuminate\Support\Facades\Log;

class CreateRole
{
    use RunsInTransaction;

    /**
     * @param  list<string>  $permissions
     */
    public function handle(string $name, array $permissions): Role
    {
        return $this->transaction(function () use ($name, $permissions): Role {
            /** @var Role $role */
            $role = Role::create(['name' => $name, 'guard_name' => 'web']);
            $role->syncPermissions($permissions);

            Log::info('role.created', [
                'role' => $role->name,
                'permissions' => count($permissions),
                'by' => auth()->id(),
            ]);

            return $role;
        });
    }
}
