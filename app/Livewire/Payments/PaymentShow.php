<?php

declare(strict_types=1);

namespace App\Livewire\Payments;

use App\Actions\Payments\AllocatePaymentAction;
use App\Actions\Payments\ReversePaymentAction;
use App\Actions\Payments\VerifyPaymentAction;
use App\Enums\PaymentStatus;
use App\Exceptions\DomainException;
use App\Models\Payment;
use App\Services\Payments\PaymentLedger;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app')]
class PaymentShow extends Component
{
    public Payment $payment;

    public bool $showReverse = false;

    public string $reverseReason = '';

    /** @var array<int, string> installment_id => amount */
    public array $manual = [];

    public bool $showManual = false;

    public function mount(Payment $payment): void
    {
        $this->authorize('view', $payment);
        $this->payment = $payment;
    }

    private function refresh(): void
    {
        $this->payment = $this->payment->fresh([
            'booking.project', 'paymentMode', 'receivedBy', 'verifiedBy', 'reversedBy',
            'allocations.installment', 'receipt',
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

    public function autoAllocate(): void
    {
        $this->authorize('allocate', $this->payment);

        try {
            app(AllocatePaymentAction::class)->handle($this->payment, null, auth()->user());
            $this->refresh();
            $this->dispatch('toast', message: 'Payment allocated.', variant: 'success');
        } catch (DomainException $e) {
            $this->dispatch('toast', message: $e->getMessage(), variant: 'danger');
        }
    }

    public function applyManual(): void
    {
        $this->authorize('allocate', $this->payment);

        $explicit = [];
        foreach ($this->manual as $installmentId => $amount) {
            if ($amount !== '' && (float) $amount > 0) {
                $explicit[] = ['installment_id' => (int) $installmentId, 'amount' => $amount];
            }
        }

        try {
            app(AllocatePaymentAction::class)->handle($this->payment, $explicit, auth()->user());
            $this->reset('showManual', 'manual');
            $this->refresh();
            $this->dispatch('toast', message: 'Allocation applied.', variant: 'success');
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
            'booking.project', 'booking.activePaymentPlan.installments', 'paymentMode',
            'receivedBy', 'verifiedBy', 'reversedBy', 'allocations.installment', 'receipt',
        ]);
        $ledger = app(PaymentLedger::class);

        return view('livewire.payments.payment-show', [
            'payment' => $payment,
            'unallocated' => $ledger->paymentUnallocated($payment),
            'allocated' => $ledger->paymentAllocated($payment),
            'openInstallments' => $payment->booking->activePaymentPlan?->installments
                ->filter(fn ($i) => $i->status->isOutstanding())
                ->map(fn ($i) => ['model' => $i, 'outstanding' => $ledger->installmentOutstanding($i)]) ?? collect(),
        ])->title($payment->payment_number);
    }
}
