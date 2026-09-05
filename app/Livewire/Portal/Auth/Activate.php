<?php

declare(strict_types=1);

namespace App\Livewire\Portal\Auth;

use App\Actions\Customers\ActivateCustomerPortal;
use App\Exceptions\DomainException;
use App\Models\CustomerInvitation;
use App\Support\Customers\CustomerTokenService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Sets the portal password from an activation (`invite`) or reset (`reset`)
 * link (M15). The token is validated on mount so an expired/spent link shows a
 * clear message rather than a form.
 */
#[Layout('components.layouts.portal-guest')]
#[Title('Set your password')]
class Activate extends Component
{
    #[Locked]
    public string $token = '';

    #[Locked]
    public string $purpose = CustomerInvitation::PURPOSE_INVITE;

    public string $password = '';

    public string $password_confirmation = '';

    public bool $tokenValid = false;

    public ?string $customerName = null;

    public function mount(string $token, string $purpose = CustomerInvitation::PURPOSE_INVITE): void
    {
        $this->token = $token;
        $this->purpose = in_array($purpose, [CustomerInvitation::PURPOSE_INVITE, CustomerInvitation::PURPOSE_RESET], true)
            ? $purpose
            : CustomerInvitation::PURPOSE_INVITE;

        $invitation = app(CustomerTokenService::class)->resolve($this->token, $this->purpose);
        $this->tokenValid = $invitation !== null;
        $this->customerName = $invitation?->buyer?->fullName();
    }

    public function submit(): void
    {
        abort_unless($this->tokenValid, 404);

        $this->validate([
            'password' => ['required', 'string', 'confirmed', PasswordRule::defaults()],
        ]);

        try {
            $customer = app(ActivateCustomerPortal::class)->handle($this->token, $this->purpose, $this->password);
        } catch (DomainException $e) {
            $this->tokenValid = false;
            $this->addError('password', $e->getMessage());

            return;
        }

        Auth::guard('customer')->login($customer);
        session()->regenerate();

        $this->redirectRoute('portal.dashboard', navigate: true);
    }

    public function render()
    {
        return view('livewire.portal.auth.activate');
    }
}
