<?php

declare(strict_types=1);

namespace App\Livewire\Roles;

use App\Actions\Roles\CreateRole;
use App\Actions\Roles\UpdateRole;
use App\Enums\Permission;
use App\Enums\PermissionGroup;
use App\Exceptions\DomainException;
use App\Models\Role;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('components.layouts.app')]
#[Title('Role')]
class RoleForm extends Component
{
    public ?Role $role = null;

    public string $name = '';

    /** @var list<string> */
    public array $permissions = [];

    public function mount(?Role $role = null): void
    {
        if ($role?->exists) {
            $this->authorize('update', $role);

            abort_if($role->isSuperAdmin(), 403, 'The Super Admin role cannot be edited.');

            $this->role = $role;
            $this->name = $role->name;
            $this->permissions = $role->permissions->pluck('name')->all();
        } else {
            $this->authorize('create', Role::class);
        }
    }

    #[Computed]
    public function editing(): bool
    {
        return $this->role !== null;
    }

    #[Computed]
    public function isSystemRole(): bool
    {
        return $this->role?->isSystem() ?? false;
    }

    /**
     * @return array<string, array{group: PermissionGroup, permissions: list<Permission>}>
     */
    #[Computed]
    public function groupedPermissions(): array
    {
        $result = [];

        foreach (Permission::grouped() as $groupValue => $perms) {
            if ($perms === []) {
                continue;
            }
            $result[$groupValue] = [
                'group' => PermissionGroup::from($groupValue),
                'permissions' => $perms,
            ];
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'name' => [
                $this->isSystemRole ? 'sometimes' : 'required',
                'string', 'max:255',
                Rule::unique('roles', 'name')->ignore($this->role?->id),
            ],
            'permissions' => ['array'],
            'permissions.*' => ['string', Rule::in(Permission::values())],
        ];
    }

    public function toggleGroup(string $group, bool $checked): void
    {
        $groupPerms = array_map(
            fn (Permission $p) => $p->value,
            Permission::grouped()[$group] ?? [],
        );

        $this->permissions = $checked
            ? array_values(array_unique([...$this->permissions, ...$groupPerms]))
            : array_values(array_diff($this->permissions, $groupPerms));
    }

    public function save()
    {
        $data = $this->validate();

        try {
            if ($this->editing) {
                app(UpdateRole::class)->handle($this->role, $data['name'] ?? $this->role->name, $this->permissions);
                $this->dispatch('toast', message: 'Role updated.', variant: 'success');
            } else {
                app(CreateRole::class)->handle($data['name'], $this->permissions);
                $this->dispatch('toast', message: 'Role created.', variant: 'success');
            }
        } catch (DomainException $e) {
            $this->addError('name', $e->getMessage());

            return;
        }

        $this->redirectRoute('roles.index', navigate: true);
    }

    public function render(): View
    {
        return view('livewire.roles.role-form');
    }
}
