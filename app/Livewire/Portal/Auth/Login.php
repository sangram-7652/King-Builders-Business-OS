<?php

declare(strict_types=1);

namespace App\Livewire\Portal\Auth;

use App\Enums\CustomerActivityType;
use App\Models\Buyer;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;

#[Layout('components.layouts.portal-guest')]
#[Title('Sign in')]
class Login extends Component
{
    #[Validate('required|string|email')]
    public string $email = '';

    #[Validate('required|string')]
    public string $password = '';

    public bool $remember = false;

    public function login(): void
    {
        $this->validate();
        $this->ensureIsNotRateLimited();

        // Email is a unique identity (F-M5-1): normalise the input the same way
        // it is stored, then resolve exactly one buyer. `orderBy('id')` keeps
        // the result deterministic even against pre-migration data.
        /** @var Buyer|null $customer */
        $customer = Buyer::query()
            ->where('email', Buyer::normalizeEmail($this->email))
            ->orderBy('id')
            ->first();

        $ok = $customer !== null
            && $customer->password !== null
            && Auth::guard('customer')->getProvider()->validateCredentials($customer, ['password' => $this->password]);

        if (! $ok || ! $customer->canAccessPortal()) {
            RateLimiter::hit($this->throttleKey(), (int) config('portal.login_decay_seconds', 60));

            $customer?->recordPortalActivity(CustomerActivityType::LoginFailed, 'Failed sign-in attempt.');

            throw ValidationException::withMessages([
                'email' => 'These credentials do not match our records, or your portal access is not active.',
            ]);
        }

        Auth::guard('customer')->login($customer, $this->remember);
        RateLimiter::clear($this->throttleKey());
        session()->regenerate();

        $customer->forceFill([
            'portal_last_login_at' => now(),
            'portal_last_login_ip' => request()->ip(),
        ])->save();
        $customer->recordPortalActivity(CustomerActivityType::Login, 'Signed in to the portal.');

        $this->redirectRoute('portal.dashboard', navigate: true);
    }

    protected function ensureIsNotRateLimited(): void
    {
        $max = (int) config('portal.login_max_attempts', 5);

        if (! RateLimiter::tooManyAttempts($this->throttleKey(), $max)) {
            return;
        }

        Event::dispatch(new Lockout(request()));
        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'email' => trans('auth.throttle', ['seconds' => $seconds, 'minutes' => ceil($seconds / 60)]),
        ]);
    }

    protected function throttleKey(): string
    {
        return 'portal|'.Str::transliterate(Str::lower($this->email).'|'.request()->ip());
    }

    public function render()
    {
        return view('livewire.portal.auth.login');
    }
}
