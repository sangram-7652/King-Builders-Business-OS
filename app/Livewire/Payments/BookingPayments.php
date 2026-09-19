<?php

declare(strict_types=1);

namespace App\Livewire\Payments;

use App\Actions\Payments\RecordPaymentAction;
use App\Actions\Payments\ReversePaymentAction;
use App\Actions\Payments\VerifyPaymentAction;
use App\Enums\PaymentStatus;
use App\Exceptions\DomainException;
use App\Models\Booking;
use App\Models\Masters\PaymentMode;
use App\Models\Payment;
use App\Services\Payments\PaymentLedger;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app')]
class BookingPayments extends Component
{
    public Booking $booking;

    // --- record payment ---
    public bool $showRecord = false;

    public string $payMode = '';

    public string $payAmount = '';

    public string $payDate = '';

    public string $payReference = '';

    public string $payNotes = '';

    public string $chequeNumber = '';

    public string $chequeBank = '';

    public string $chequeDate = '';

    // --- reverse ---
    public ?int $reversingPaymentId = null;

    public string $reverseReason = '';

    public function mount(Booking $booking): void
    {
        $this->authorize('viewAny', Payment::class);
        abort_unless($booking->isConfirmed(), 404);
        $this->booking = $booking;
        $this->payDate = now()->toDateString();
    }

    // --- Payment actions -------------------------------------------

    public function recordPayment(): void
    {
        $this->authorize('create', Payment::class);

        $this->validate([
            'payMode' => ['required', 'exists:payment_modes,id'],
            'payAmount' => ['required', 'numeric', 'gt:0'],
            'payDate' => ['required', 'date'],
            'payReference' => ['nullable', 'string', 'max:255'],
            'chequeNumber' => ['nullable', 'string', 'max:64'],
        ]);

        try {
            app(RecordPaymentAction::class)->handle([
                'booking_id' => $this->booking->id,
                'payment_mode_id' => (int) $this->payMode,
                'amount' => $this->payAmount,
                'payment_date' => $this->payDate,
                'reference_number' => $this->payReference ?: null,
                'notes' => $this->payNotes ?: null,
                'cheque_number' => $this->chequeNumber ?: null,
                'cheque_bank_name' => $this->chequeBank ?: null,
                'cheque_date' => $this->chequeDate ?: null,
            ], auth()->user());

            $this->reset('showRecord', 'payAmount', 'payReference', 'payNotes', 'chequeNumber', 'chequeBank', 'chequeDate');
            $this->dispatch('toast', message: 'Payment recorded (pending verification).', variant: 'success');
        } catch (DomainException $e) {
            $this->dispatch('toast', message: $e->getMessage(), variant: 'danger');
        }
    }

    public function verify(int $paymentId, string $outcome): void
    {
        $payment = $this->booking->payments()->findOrFail($paymentId);
        $this->authorize('verify', $payment);

        $target = $outcome === 'success' ? PaymentStatus::Success : PaymentStatus::Failed;

        try {
            app(VerifyPaymentAction::class)->handle($payment, $target, auth()->user());
            $this->dispatch('toast', message: "Payment marked {$target->label()}.", variant: 'success');
        } catch (DomainException $e) {
            $this->dispatch('toast', message: $e->getMessage(), variant: 'danger');
        }
    }

    public function openReverse(int $paymentId): void
    {
        $this->reversingPaymentId = $paymentId;
        $this->reverseReason = '';
    }

    public function reverse(): void
    {
        $payment = $this->booking->payments()->findOrFail($this->reversingPaymentId);
        $this->authorize('reverse', $payment);
        $this->validate(['reverseReason' => ['required', 'string', 'min:3', 'max:255']]);

        try {
            app(ReversePaymentAction::class)->handle($payment, $this->reverseReason, auth()->user());
            $this->reset('reversingPaymentId', 'reverseReason');
            $this->dispatch('toast', message: 'Payment reversed.', variant: 'success');
        } catch (DomainException $e) {
            $this->dispatch('toast', message: $e->getMessage(), variant: 'danger');
        }
    }

    public function render(): View
    {
        $booking = $this->booking->fresh(['project', 'plot']);
        $ledger = app(PaymentLedger::class);

        $payments = $booking->payments()
            ->with(['paymentMode', 'receipt'])
            ->orderByDesc('id')
            ->get();

        return view('livewire.payments.booking-payments', [
            'booking' => $booking,
            'summary' => $ledger->summary($booking),
            'payments' => $payments,
            'paymentModes' => PaymentMode::query()->where('is_active', true)->orderBy('sort_order')->get(),
        ])->title("Payments · {$booking->booking_number}");
    }
}
