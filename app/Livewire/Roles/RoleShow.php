<?php

declare(strict_types=1);

namespace App\Livewire\Roles;

use App\Enums\Permission;
use App\Enums\PermissionGroup;
use App\Models\Role;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('components.layouts.app')]
#[Title('Role')]
class RoleShow extends Component
{
    public Role $role;

    public function mount(Role $role): void
    {
        $this->authorize('view', $role);
        $this->role = $role->load('permissions');
    }

    public function render(): View
    {
        $held = $this->role->permissions->pluck('name')->all();

        $groups = [];
        foreach (Permission::grouped() as $groupValue => $perms) {
            $selected = array_values(array_filter($perms, fn (Permission $p) => in_array($p->value, $held, true)));
            if ($selected !== []) {
                $groups[] = ['group' => PermissionGroup::from($groupValue), 'permissions' => $selected];
            }
        }

        return view('livewire.roles.role-show', [
            'groups' => $groups,
            'userCount' => $this->role->users()->count(),
        ]);
    }
}
