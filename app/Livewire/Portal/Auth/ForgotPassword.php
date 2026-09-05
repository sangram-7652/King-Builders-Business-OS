<?php

declare(strict_types=1);

namespace App\Livewire\Portal\Auth;

use App\Actions\Customers\RequestCustomerPasswordReset;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;

#[Layout('components.layouts.portal-guest')]
#[Title('Forgot password')]
class ForgotPassword extends Component
{
    #[Validate('required|string|email')]
    public string $email = '';

    public bool $sent = false;

    public function request(): void
    {
        $this->validate();

        // Enumeration-safe: always the same outcome. No mail is sent — the
        // request is logged for staff to action a reset link.
        app(RequestCustomerPasswordReset::class)->forEmail($this->email);

        $this->sent = true;
        $this->reset('email');
    }

    public function render()
    {
        return view('livewire.portal.auth.forgot-password');
    }
}
