<?php

declare(strict_types=1);

namespace App\Livewire\Payments;

use App\Actions\Payments\ReversePaymentAction;
use App\Actions\Payments\VerifyPaymentAction;
use App\Enums\PaymentStatus;
use App\Exceptions\DomainException;
use App\Models\Payment;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app')]
class PaymentShow extends Component
{
    public Payment $payment;

    public bool $showReverse = false;

    public string $reverseReason = '';

    public function mount(Payment $payment): void
    {
        $this->authorize('view', $payment);
        $this->payment = $payment;
    }

    private function refresh(): void
    {
        $this->payment = $this->payment->fresh([
            'booking.project', 'paymentMode', 'receivedBy', 'verifiedBy', 'reversedBy', 'receipt',
        ]);
    }

    public function verify(string $outcome): void
    {
        $this->authorize('verify', $this->payment);
        $target = $outcome === 'success' ? PaymentStatus::Success : PaymentStatus::Failed;

        try {
            app(VerifyPaymentAction::class)->handle($this->payment, $target, auth()->user());
            $this->refresh();
            $this->dispatch('toast', message: "Payment marked {$target->label()}.", variant: 'success');
        } catch (DomainException $e) {
            $this->dispatch('toast', message: $e->getMessage(), variant: 'danger');
        }
    }

    public function reverse(): void
    {
        $this->authorize('reverse', $this->payment);
        $this->validate(['reverseReason' => ['required', 'string', 'min:3', 'max:255']]);

        try {
            app(ReversePaymentAction::class)->handle($this->payment, $this->reverseReason, auth()->user());
            $this->reset('showReverse', 'reverseReason');
            $this->refresh();
            $this->dispatch('toast', message: 'Payment reversed.', variant: 'success');
        } catch (DomainException $e) {
            $this->dispatch('toast', message: $e->getMessage(), variant: 'danger');
        }
    }

    public function render(): View
    {
        $payment = $this->payment->load([
            'booking.project', 'paymentMode', 'receivedBy', 'verifiedBy', 'reversedBy', 'receipt',
        ]);

        return view('livewire.payments.payment-show', [
            'payment' => $payment,
        ])->title($payment->payment_number);
    }
}
