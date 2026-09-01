<?php

declare(strict_types=1);

namespace App\Livewire\Users;

use App\Actions\Users\DeleteUser;
use App\Actions\Users\ToggleUserStatus;
use App\Enums\UserStatus;
use App\Exceptions\DomainException;
use App\Models\Role;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.layouts.app')]
#[Title('Users')]
class UserIndex extends Component
{
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url]
    public string $status = '';

    #[Url]
    public string $role = '';

    #[Url]
    public int $perPage = 15;

    public function mount(): void
    {
        $this->authorize('viewAny', User::class);
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'status', 'role', 'perPage'], true)) {
            $this->resetPage();
        }
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'status', 'role');
        $this->resetPage();
    }

    public function toggleStatus(int $user): void
    {
        $model = User::findOrFail($user);
        $this->authorize('toggleStatus', $model);

        try {
            $updated = app(ToggleUserStatus::class)->handle($model);
            $this->dispatch('toast', message: "User {$updated->status->label()}.", variant: 'success');
        } catch (DomainException $e) {
            $this->dispatch('toast', message: $e->getMessage(), variant: 'danger');
        }
    }

    public function delete(int $user): void
    {
        $model = User::findOrFail($user);
        $this->authorize('delete', $model);

        try {
            app(DeleteUser::class)->handle($model);
            $this->dispatch('toast', message: 'User deleted.', variant: 'success');
        } catch (DomainException $e) {
            $this->dispatch('toast', message: $e->getMessage(), variant: 'danger');
        }
    }

    /**
     * @return Builder<User>
     */
    protected function query(): Builder
    {
        return User::query()
            ->with('roles:id,name')
            ->when($this->search !== '', function (Builder $q): void {
                $term = '%'.$this->search.'%';
                $q->where(function (Builder $q) use ($term): void {
                    $q->where('name', 'like', $term)
                        ->orWhere('email', 'like', $term)
                        ->orWhere('mobile', 'like', $term);
                });
            })
            ->when($this->status !== '', fn (Builder $q) => $q->where('status', $this->status))
            ->when($this->role !== '', fn (Builder $q) => $q->whereHas('roles', fn (Builder $r) => $r->where('name', $this->role)))
            ->orderBy('name');
    }

    public function render(): View
    {
        return view('livewire.users.user-index', [
            'users' => $this->query()->paginate($this->perPage),
            'statuses' => UserStatus::options(),
            'roles' => Role::orderBy('name')->pluck('name'),
        ]);
    }
}
