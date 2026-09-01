<?php

declare(strict_types=1);

namespace App\Livewire\Collections;

use App\Actions\Collections\ApproveBouncePenaltyAction;
use App\Actions\Collections\AssessBouncePenaltyAction;
use App\Actions\Collections\AssignCollectionCaseAction;
use App\Actions\Collections\CancelPaymentPromiseAction;
use App\Actions\Collections\CompleteCollectionFollowUpAction;
use App\Actions\Collections\CreatePaymentPromiseAction;
use App\Actions\Collections\RecordChequeBounceAction;
use App\Actions\Collections\ScheduleCollectionFollowUpAction;
use App\Actions\Collections\UpdateCollectionCaseAction;
use App\Enums\CollectionCaseStatus;
use App\Enums\CollectionFollowUpOutcome;
use App\Exceptions\DomainException;
use App\Models\BouncePenalty;
use App\Models\ChequeBounce;
use App\Models\CollectionCase;
use App\Models\CollectionFollowUp;
use App\Models\Payment;
use App\Models\PaymentPromise;
use App\Models\User;
use App\Services\Collections\AgingCalculator;
use App\Services\Payments\PaymentLedger;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app')]
class CollectionCaseShow extends Component
{
    public CollectionCase $case;

    // assign
    public string $assignTo = '';

    // schedule follow-up
    public bool $showSchedule = false;

    public string $scheduleAt = '';

    public string $scheduleNotes = '';

    // complete follow-up
    public ?int $completingId = null;

    public string $completeOutcome = '';

    public string $completeNotes = '';

    public string $completeNext = '';

    // promise
    public bool $showPromise = false;

    public string $promiseAmount = '';

    public string $promiseDate = '';

    public string $promiseNotes = '';

    // cheque bounce
    public ?int $bouncingPaymentId = null;

    public string $bounceDate = '';

    public string $bounceReason = '';

    public string $bounceCharges = '0';

    // penalty
    public ?int $assessingBounceId = null;

    public string $penaltyAmount = '';

    public string $penaltyReason = '';

    public function mount(CollectionCase $case): void
    {
        $this->authorize('view', $case);
        $this->case = $case;
        $this->assignTo = (string) ($case->assigned_to ?? '');
        $this->promiseDate = now()->addDays(3)->toDateString();
        $this->bounceDate = now()->toDateString();
        $this->refresh();
    }

    private function refresh(): void
    {
        $this->case = $this->case->fresh([
            'booking.project', 'booking.plot', 'booking.primaryBookingBuyer.buyer',
            'assignedTo', 'openedBy',
            'followUps.assignedTo', 'promises.createdBy', 'activities.causer',
            'chequeBounces.payment', 'chequeBounces.penalties',
        ]);
    }

    // --- Assign --------------------------------------------------------

    public function assign(): void
    {
        $this->authorize('assign', $this->case);

        try {
            $assignee = $this->assignTo !== '' ? User::find($this->assignTo) : null;
            app(AssignCollectionCaseAction::class)->handle($this->case, $assignee, auth()->user());
            $this->refresh();
            $this->dispatch('toast', message: 'Case assignment updated.', variant: 'success');
        } catch (DomainException $e) {
            $this->dispatch('toast', message: $e->getMessage(), variant: 'danger');
        }
    }

    public function setStatus(string $status): void
    {
        $this->authorize('update', $this->case);

        try {
            app(UpdateCollectionCaseAction::class)->handle($this->case, ['status' => $status], auth()->user());
            $this->refresh();
            $this->dispatch('toast', message: 'Case status updated.', variant: 'success');
        } catch (DomainException $e) {
            $this->dispatch('toast', message: $e->getMessage(), variant: 'danger');
        }
    }

    // --- Follow-ups ------------------------------------------------

    public function scheduleFollowUp(): void
    {
        $this->authorize('followUp', $this->case);
        $this->validate(['scheduleAt' => ['required', 'date'], 'scheduleNotes' => ['nullable', 'string', 'max:1000']]);

        app(ScheduleCollectionFollowUpAction::class)->handle($this->case, [
            'follow_up_at' => $this->scheduleAt,
            'notes' => $this->scheduleNotes ?: null,
        ], auth()->user());

        $this->reset('showSchedule', 'scheduleAt', 'scheduleNotes');
        $this->refresh();
        $this->dispatch('toast', message: 'Follow-up scheduled.', variant: 'success');
    }

    public function completeFollowUp(): void
    {
        $this->authorize('followUp', $this->case);
        $this->validate([
            'completeOutcome' => ['required', 'string'],
            'completeNotes' => ['nullable', 'string', 'max:1000'],
            'completeNext' => ['nullable', 'date'],
        ]);

        $followUp = CollectionFollowUp::where('collection_case_id', $this->case->id)->findOrFail($this->completingId);

        try {
            app(CompleteCollectionFollowUpAction::class)->handle($followUp, [
                'outcome' => $this->completeOutcome,
                'notes' => $this->completeNotes ?: null,
                'next_follow_up_at' => $this->completeNext ?: null,
            ], auth()->user());
            $this->reset('completingId', 'completeOutcome', 'completeNotes', 'completeNext');
            $this->refresh();
            $this->dispatch('toast', message: 'Follow-up completed.', variant: 'success');
        } catch (DomainException $e) {
            $this->dispatch('toast', message: $e->getMessage(), variant: 'danger');
        }
    }

    // --- Promises -------------------------------------------------

    public function createPromise(): void
    {
        $this->authorize('create', PaymentPromise::class);
        $this->validate([
            'promiseAmount' => ['required', 'numeric', 'gt:0'],
            'promiseDate' => ['required', 'date'],
            'promiseNotes' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            app(CreatePaymentPromiseAction::class)->handle([
                'booking_id' => $this->case->booking_id,
                'promised_amount' => $this->promiseAmount,
                'promise_date' => $this->promiseDate,
                'notes' => $this->promiseNotes ?: null,
            ], auth()->user());
            $this->reset('showPromise', 'promiseAmount', 'promiseNotes');
            $this->refresh();
            $this->dispatch('toast', message: 'Promise recorded (this is not a payment).', variant: 'success');
        } catch (DomainException $e) {
            $this->dispatch('toast', message: $e->getMessage(), variant: 'danger');
        }
    }

    public function cancelPromise(int $promiseId): void
    {
        $promise = $this->case->promises()->findOrFail($promiseId);
        $this->authorize('update', $promise);

        try {
            app(CancelPaymentPromiseAction::class)->handle($promise, auth()->user(), 'Cancelled from case');
            $this->refresh();
            $this->dispatch('toast', message: 'Promise cancelled.', variant: 'success');
        } catch (DomainException $e) {
            $this->dispatch('toast', message: $e->getMessage(), variant: 'danger');
        }
    }

    // --- Cheque bounce + penalty --------------------------------

    public function recordBounce(): void
    {
        $this->authorize('bounce', ChequeBounce::class);
        $this->validate([
            'bounceDate' => ['required', 'date'],
            'bounceReason' => ['required', 'string', 'max:255'],
            'bounceCharges' => ['nullable', 'numeric', 'min:0'],
        ]);

        $payment = Payment::where('booking_id', $this->case->booking_id)->findOrFail($this->bouncingPaymentId);

        try {
            app(RecordChequeBounceAction::class)->handle($payment, [
                'bounce_date' => $this->bounceDate,
                'bounce_reason' => $this->bounceReason,
                'bank_charges' => $this->bounceCharges ?: 0,
            ], auth()->user());
            $this->reset('bouncingPaymentId', 'bounceReason', 'bounceCharges');
            $this->refresh();
            $this->dispatch('toast', message: 'Cheque bounce recorded.', variant: 'success');
        } catch (DomainException $e) {
            $this->dispatch('toast', message: $e->getMessage(), variant: 'danger');
        }
    }

    public function assessPenalty(): void
    {
        $this->authorize('assess', BouncePenalty::class);
        $this->validate([
            'penaltyAmount' => ['required', 'numeric', 'gt:0'],
            'penaltyReason' => ['required', 'string', 'max:255'],
        ]);

        $bounce = $this->case->chequeBounces()->findOrFail($this->assessingBounceId);

        try {
            app(AssessBouncePenaltyAction::class)->handle($bounce, [
                'penalty_amount' => $this->penaltyAmount,
                'reason' => $this->penaltyReason,
            ], auth()->user());
            $this->reset('assessingBounceId', 'penaltyAmount', 'penaltyReason');
            $this->refresh();
            $this->dispatch('toast', message: 'Penalty assessed (pending approval).', variant: 'success');
        } catch (DomainException $e) {
            $this->dispatch('toast', message: $e->getMessage(), variant: 'danger');
        }
    }

    public function approvePenalty(int $penaltyId): void
    {
        $penalty = BouncePenalty::where('booking_id', $this->case->booking_id)->findOrFail($penaltyId);
        $this->authorize('approve', $penalty);

        try {
            app(ApproveBouncePenaltyAction::class)->handle($penalty, auth()->user());
            $this->refresh();
            $this->dispatch('toast', message: 'Penalty approved.', variant: 'success');
        } catch (DomainException $e) {
            $this->dispatch('toast', message: $e->getMessage(), variant: 'danger');
        }
    }

    public function render(): View
    {
        $ledger = app(PaymentLedger::class);
        $aging = app(AgingCalculator::class);
        $booking = $this->case->booking;

        return view('livewire.collections.collection-case-show', [
            'summary' => $ledger->summary($booking),
            'overdueInstallments' => $aging->overdueInstallments($booking),
            'chequePayments' => $booking->payments()
                ->whereNotNull('cheque_status')
                ->with('chequeBounce')
                ->get(),
            'outcomes' => CollectionFollowUpOutcome::options(),
            'statuses' => collect(CollectionCaseStatus::assignableStatuses())->mapWithKeys(fn ($s) => [$s->value => $s->label()]),
            'owners' => auth()->user()->can('collections.assign')
                ? User::query()->where('status', 'active')->orderBy('name')->pluck('name', 'id')
                : collect(),
        ])->title("Collection · {$booking->booking_number}");
    }
}
