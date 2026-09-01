<?php

declare(strict_types=1);

namespace App\Livewire\Users;

use App\Models\User;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('components.layouts.app')]
#[Title('User')]
class UserShow extends Component
{
    public User $user;

    public function mount(User $user): void
    {
        $this->authorize('view', $user);
        $this->user = $user->load('roles.permissions');
    }

    public function render(): View
    {
        return view('livewire.users.user-show', [
            'permissions' => $this->user->getAllPermissions()->pluck('name')->sort()->values(),
        ]);
    }
}
