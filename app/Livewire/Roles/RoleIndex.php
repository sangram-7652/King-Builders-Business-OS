<?php

declare(strict_types=1);

namespace App\Livewire\Roles;

use App\Actions\Roles\DeleteRole;
use App\Exceptions\DomainException;
use App\Models\Role;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('components.layouts.app')]
#[Title('Roles & Permissions')]
class RoleIndex extends Component
{
    public function mount(): void
    {
        $this->authorize('viewAny', Role::class);
    }

    public function delete(int $role): void
    {
        $model = Role::findOrFail($role);
        $this->authorize('delete', $model);

        try {
            app(DeleteRole::class)->handle($model);
            $this->dispatch('toast', message: 'Role deleted.', variant: 'success');
        } catch (DomainException $e) {
            $this->dispatch('toast', message: $e->getMessage(), variant: 'danger');
        }
    }

    public function render(): View
    {
        return view('livewire.roles.role-index', [
            'roles' => Role::query()
                ->withCount(['users', 'permissions'])
                ->orderBy('name')
                ->get(),
        ]);
    }
}
