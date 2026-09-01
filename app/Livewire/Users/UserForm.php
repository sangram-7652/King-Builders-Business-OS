<?php

declare(strict_types=1);

namespace App\Livewire\Users;

use App\Actions\Users\CreateUser;
use App\Actions\Users\SetUserPassword;
use App\Actions\Users\UpdateUser;
use App\Enums\UserStatus;
use App\Exceptions\DomainException;
use App\Models\Role;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('components.layouts.app')]
#[Title('User')]
class UserForm extends Component
{
    public ?User $user = null;

    public string $name = '';

    public string $email = '';

    public string $mobile = '';

    public string $status = UserStatus::Active->value;

    /** @var list<string> */
    public array $roles = [];

    public string $password = '';

    public string $password_confirmation = '';

    public function mount(?User $user = null): void
    {
        if ($user?->exists) {
            $this->authorize('update', $user);
            $this->user = $user;
            $this->name = $user->name;
            $this->email = $user->email;
            $this->mobile = (string) $user->mobile;
            $this->status = $user->status->value;
            $this->roles = $user->roles->pluck('name')->all();
        } else {
            $this->authorize('create', User::class);
        }
    }

    #[Computed]
    public function editing(): bool
    {
        return $this->user !== null;
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required', 'string', 'email', 'max:255',
                Rule::unique('users', 'email')->ignore($this->user?->id),
            ],
            'mobile' => ['nullable', 'string', 'max:32'],
            'status' => ['required', Rule::enum(UserStatus::class)],
            'roles' => ['array'],
            'roles.*' => ['string', Rule::exists('roles', 'name')],
            'password' => [
                $this->editing ? 'nullable' : 'required',
                'nullable', 'string', 'confirmed', PasswordRule::defaults(),
            ],
        ];
    }

    public function save()
    {
        $data = $this->validate();

        try {
            if ($this->editing) {
                app(UpdateUser::class)->handle(
                    user: $this->user,
                    name: $data['name'],
                    email: $data['email'],
                    mobile: $data['mobile'] ?: null,
                    status: UserStatus::from($data['status']),
                    roleNames: $this->roles,
                );

                if (! empty($data['password'])) {
                    app(SetUserPassword::class)->handle($this->user, $data['password']);
                }

                $this->dispatch('toast', message: 'User updated.', variant: 'success');
            } else {
                app(CreateUser::class)->handle(
                    name: $data['name'],
                    email: $data['email'],
                    mobile: $data['mobile'] ?: null,
                    password: $data['password'],
                    status: UserStatus::from($data['status']),
                    roleNames: $this->roles,
                );

                $this->dispatch('toast', message: 'User created.', variant: 'success');
            }
        } catch (DomainException $e) {
            $this->addError('roles', $e->getMessage());

            return;
        }

        $this->redirectRoute('users.index', navigate: true);
    }

    public function render(): View
    {
        return view('livewire.users.user-form', [
            'availableRoles' => Role::orderBy('name')->pluck('name'),
            'statuses' => UserStatus::options(),
        ]);
    }
}
