<?php

declare(strict_types=1);

namespace App\Actions\Roles;

use App\Exceptions\DomainException;
use App\Models\Role;
use Illuminate\Support\Facades\Log;

class DeleteRole
{
    public function handle(Role $role): void
    {
        if ($role->isSystem()) {
            throw new DomainException('System roles cannot be deleted.');
        }

        if ($role->users()->count() > 0) {
            throw new DomainException('This role is assigned to one or more users. Reassign them first.');
        }

        $name = $role->name;
        $role->delete();

        Log::info('role.deleted', ['role' => $name, 'by' => auth()->id()]);
    }
}
