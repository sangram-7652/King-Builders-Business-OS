<?php

declare(strict_types=1);

namespace App\Livewire\Auth;

use Illuminate\Support\Facades\Password;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;

#[Layout('components.layouts.guest')]
#[Title('Forgot password')]
class ForgotPassword extends Component
{
    #[Validate('required|string|email')]
    public string $email = '';

    public function sendResetLink(): void
    {
        $this->validate();

        // Always returns a generic status — never reveals whether the email exists.
        Password::sendResetLink(['email' => $this->email]);

        session()->flash('status', 'If that email is registered, a password reset link has been sent.');

        $this->reset('email');
    }

    public function render()
    {
        return view('livewire.auth.forgot-password');
    }
}
