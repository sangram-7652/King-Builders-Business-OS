<?php

declare(strict_types=1);

namespace App\Livewire\Payments;

use App\Actions\Payments\ActivatePaymentPlanAction;
use App\Actions\Payments\AllocatePaymentAction;
use App\Actions\Payments\CancelPaymentPlanAction;
use App\Actions\Payments\CreatePaymentPlanAction;
use App\Actions\Payments\RecordPaymentAction;
use App\Actions\Payments\ReversePaymentAction;
use App\Actions\Payments\VerifyPaymentAction;
use App\Enums\PaymentStatus;
use App\Exceptions\DomainException;
use App\Models\Booking;
use App\Models\Masters\PaymentMode;
use App\Models\Payment;
use App\Models\PaymentPlan;
use App\Services\Payments\PaymentLedger;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app')]
class BookingPayments extends Component
{
    public Booking $booking;

    // --- plan builder ---
    public bool $showPlanBuilder = false;

    public string $scheduleType = 'percentage';

    /** @var array<int, array{value: string, due_date: string, name: string}> */
    public array $rows = [];

    public string $planName = 'Payment plan';

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
        $this->authorize('viewAny', PaymentPlan::class);
        abort_unless($booking->isConfirmed(), 404);
        $this->booking = $booking;
        $this->payDate = now()->toDateString();
        $this->resetRows();
    }

    private function resetRows(): void
    {
        $this->rows = [
            ['value' => '25', 'due_date' => now()->addMonth()->toDateString(), 'name' => ''],
            ['value' => '25', 'due_date' => now()->addMonths(2)->toDateString(), 'name' => ''],
            ['value' => '25', 'due_date' => now()->addMonths(3)->toDateString(), 'name' => ''],
            ['value' => '25', 'due_date' => now()->addMonths(4)->toDateString(), 'name' => ''],
        ];
    }

    public function addRow(): void
    {
        $this->rows[] = ['value' => '', 'due_date' => now()->addMonths(count($this->rows) + 1)->toDateString(), 'name' => ''];
    }

    public function removeRow(int $i): void
    {
        unset($this->rows[$i]);
        $this->rows = array_values($this->rows);
    }

    // --- Plan actions -------------------------------------------------

    public function createPlan(): void
    {
        $this->authorize('create', PaymentPlan::class);

        try {
            app(CreatePaymentPlanAction::class)->handle($this->booking, [
                'name' => $this->planName,
                'schedule' => array_map(fn ($r) => [
                    'type' => $this->scheduleType,
                    'value' => $r['value'],
                    'due_date' => $r['due_date'],
                    'name' => $r['name'] ?: null,
                ], $this->rows),
            ], auth()->user());

            $this->showPlanBuilder = false;
            $this->dispatch('toast', message: 'Payment plan created.', variant: 'success');
        } catch (DomainException $e) {
            $this->dispatch('toast', message: $e->getMessage(), variant: 'danger');
        }
    }

    public function activatePlan(int $planId): void
    {
        $plan = $this->booking->paymentPlans()->findOrFail($planId);
        $this->authorize('activate', $plan);

        try {
            app(ActivatePaymentPlanAction::class)->handle($plan, auth()->user());
            $this->dispatch('toast', message: 'Payment plan activated.', variant: 'success');
        } catch (DomainException $e) {
            $this->dispatch('toast', message: $e->getMessage(), variant: 'danger');
        }
    }

    public function cancelPlan(int $planId): void
    {
        $plan = $this->booking->paymentPlans()->findOrFail($planId);
        $this->authorize('cancel', $plan);

        try {
            app(CancelPaymentPlanAction::class)->handle($plan, auth()->user(), 'Cancelled from booking payments');
            $this->dispatch('toast', message: 'Payment plan cancelled.', variant: 'success');
        } catch (DomainException $e) {
            $this->dispatch('toast', message: $e->getMessage(), variant: 'danger');
        }
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

    public function autoAllocate(int $paymentId): void
    {
        $payment = $this->booking->payments()->findOrFail($paymentId);
        $this->authorize('allocate', $payment);

        try {
            app(AllocatePaymentAction::class)->handle($payment, null, auth()->user());
            $this->dispatch('toast', message: 'Payment allocated.', variant: 'success');
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
        $booking = $this->booking->fresh(['project', 'plot', 'activePaymentPlan.installments', 'paymentPlans']);
        $ledger = app(PaymentLedger::class);

        $payments = $booking->payments()
            ->with(['paymentMode', 'receipt', 'allocations'])
            ->orderByDesc('id')
            ->get();

        $plan = $booking->activePaymentPlan;

        return view('livewire.payments.booking-payments', [
            'booking' => $booking,
            'summary' => $ledger->summary($booking),
            'plan' => $plan,
            'installments' => $plan?->installments->map(fn ($i) => [
                'model' => $i,
                'paid' => $ledger->installmentPaid($i),
                'outstanding' => $ledger->installmentOutstanding($i),
            ]) ?? collect(),
            'payments' => $payments->map(fn ($p) => [
                'model' => $p,
                'unallocated' => $ledger->paymentUnallocated($p),
            ]),
            'paymentModes' => PaymentMode::query()->where('is_active', true)->orderBy('sort_order')->get(),
            'ledger' => $ledger,
        ])->title("Payments · {$booking->booking_number}");
    }
}
