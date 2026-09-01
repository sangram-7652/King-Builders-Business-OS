<?php

declare(strict_types=1);

namespace App\Livewire\Bookings;

use App\Actions\Handover\HandoverWorkflowAction;
use App\Actions\Registry\ApproveRegistryExpenseAction;
use App\Actions\Registry\InitiateRegistryCaseAction;
use App\Actions\Registry\RecordRegistryExpenseAction;
use App\Actions\Registry\RefreshRegistryEligibilityAction;
use App\Actions\Registry\RegistryCaseWorkflowAction;
use App\Actions\Registry\ScheduleRegistryAppointmentAction;
use App\Enums\RegistryExpenseType;
use App\Exceptions\DomainException;
use App\Models\Booking;
use App\Models\RegistryCase;
use App\Models\RegistryExpense;
use App\Models\User;
use App\Services\Registry\RegistryEligibilityService;
use App\Support\Money;
use Illuminate\Contracts\View\View;
use Illuminate\Http\UploadedFile;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;

#[Layout('components.layouts.app')]
class BookingRegistry extends Component
{
    use WithFileUploads;

    public Booking $booking;

    // schedule
    public bool $showSchedule = false;

    public bool $rescheduleMode = false;

    public string $scheduledAt = '';

    public string $registryOffice = '';

    public string $appointmentReference = '';

    public string $responsibleUser = '';

    public string $scheduleReason = '';

    // complete
    public bool $showComplete = false;

    public string $registeredDocNumber = '';

    public string $registrationDate = '';

    /** @var UploadedFile|null */
    public $registeredDeed = null;

    // hold
    public string $holdReason = '';

    public bool $showHold = false;

    // expense
    public bool $showExpense = false;

    public string $expenseType = 'stamp_duty';

    public string $expenseAmount = '';

    public string $expenseReference = '';

    public string $expensePaidBy = '';

    // handover
    public bool $showHandoverComplete = false;

    public string $handoverDate = '';

    public string $handoverReceivedBy = '';

    public string $handoverNotes = '';

    /** @var UploadedFile|null */
    public $handoverAck = null;

    public function mount(Booking $booking): void
    {
        $this->authorize('viewAny', RegistryCase::class);
        $this->authorize('view', $booking);
        abort_unless($booking->isConfirmed(), 404);
        $this->booking = $booking;
        $this->registrationDate = now()->toDateString();
        $this->handoverDate = now()->toDateString();
    }

    private function case()
    {
        return $this->booking->registryCase()->firstOrFail();
    }

    // --- Case lifecycle -----------------------------------------

    public function initiate(): void
    {
        $this->authorize('create', RegistryCase::class);

        try {
            app(InitiateRegistryCaseAction::class)->handle($this->booking, auth()->user());
            $this->dispatch('toast', message: 'Registry case opened.', variant: 'success');
        } catch (DomainException $e) {
            $this->dispatch('toast', message: $e->getMessage(), variant: 'danger');
        }
    }

    public function refreshEligibility(): void
    {
        $case = $this->case();
        $this->authorize('update', $case);
        app(RefreshRegistryEligibilityAction::class)->handle($case, auth()->user());
        $this->dispatch('toast', message: 'Eligibility re-checked.', variant: 'success');
    }

    public function markInProcess(): void
    {
        $case = $this->case();
        $this->authorize('update', $case);
        $this->run(fn () => app(RegistryCaseWorkflowAction::class)->markInProcess($case, auth()->user()), 'Registry marked in process.');
    }

    public function schedule(bool $reschedule = false): void
    {
        $case = $this->case();
        $this->authorize('schedule', $case);
        $this->validate([
            'scheduledAt' => ['required', 'date'],
            'registryOffice' => ['required', 'string', 'max:255'],
        ]);

        try {
            app(ScheduleRegistryAppointmentAction::class)->handle($case, [
                'scheduled_at' => $this->scheduledAt,
                'registry_office' => $this->registryOffice,
                'appointment_reference' => $this->appointmentReference ?: null,
                'responsible_user_id' => $this->responsibleUser ? (int) $this->responsibleUser : null,
                'reason' => $this->scheduleReason ?: null,
            ], auth()->user(), $reschedule || $this->rescheduleMode);

            $this->reset('showSchedule', 'rescheduleMode', 'scheduledAt', 'registryOffice', 'appointmentReference', 'responsibleUser', 'scheduleReason');
            $this->dispatch('toast', message: 'Appointment saved.', variant: 'success');
        } catch (DomainException $e) {
            $this->dispatch('toast', message: $e->getMessage(), variant: 'danger');
        }
    }

    public function complete(): void
    {
        $case = $this->case();
        $this->authorize('complete', $case);
        $this->validate([
            'registeredDocNumber' => ['required', 'string', 'max:255'],
            'registrationDate' => ['required', 'date'],
            'registeredDeed' => ['nullable', 'file', 'max:'.config('registry.uploads.max_kb'), 'mimes:'.implode(',', config('registry.uploads.mimes'))],
        ]);

        try {
            app(RegistryCaseWorkflowAction::class)->complete($case, [
                'registered_document_number' => $this->registeredDocNumber,
                'registration_date' => $this->registrationDate,
            ], auth()->user(), $this->registeredDeed);

            $this->reset('showComplete', 'registeredDocNumber', 'registeredDeed');
            $this->dispatch('toast', message: 'Registry completed. Handover opened.', variant: 'success');
        } catch (DomainException $e) {
            $this->dispatch('toast', message: $e->getMessage(), variant: 'danger');
        }
    }

    public function putOnHold(): void
    {
        $case = $this->case();
        $this->authorize('update', $case);
        $this->validate(['holdReason' => ['required', 'string', 'min:3', 'max:255']]);
        $this->run(fn () => app(RegistryCaseWorkflowAction::class)->putOnHold($case, $this->holdReason, auth()->user()), 'Registry on hold.');
        $this->reset('showHold', 'holdReason');
    }

    public function resume(): void
    {
        $case = $this->case();
        $this->authorize('update', $case);
        $this->run(fn () => app(RegistryCaseWorkflowAction::class)->resume($case, auth()->user()), 'Registry resumed.');
    }

    public function cancelCase(): void
    {
        $case = $this->case();
        $this->authorize('update', $case);
        $this->run(fn () => app(RegistryCaseWorkflowAction::class)->cancel($case, 'Cancelled from booking registry', auth()->user()), 'Registry cancelled.');
    }

    // --- Expenses ------------------------------------------------

    public function recordExpense(): void
    {
        $this->authorize('create', RegistryExpense::class);
        $this->validate([
            'expenseType' => ['required'],
            'expenseAmount' => ['required', 'numeric', 'gt:0'],
        ]);

        try {
            app(RecordRegistryExpenseAction::class)->handle($this->case(), [
                'expense_type' => $this->expenseType,
                'amount' => $this->expenseAmount,
                'reference' => $this->expenseReference ?: null,
                'paid_by' => $this->expensePaidBy ?: null,
            ], auth()->user());
            $this->reset('showExpense', 'expenseAmount', 'expenseReference', 'expensePaidBy');
            $this->dispatch('toast', message: 'Expense recorded.', variant: 'success');
        } catch (DomainException $e) {
            $this->dispatch('toast', message: $e->getMessage(), variant: 'danger');
        }
    }

    public function approveExpense(int $id): void
    {
        $expense = $this->case()->expenses()->findOrFail($id);
        $this->authorize('approve', $expense);
        $this->run(fn () => app(ApproveRegistryExpenseAction::class)->handle($expense, auth()->user()), 'Expense approved.');
    }

    // --- Handover -----------------------------------------------

    public function handoverDocsReady(): void
    {
        $h = $this->booking->documentHandover()->firstOrFail();
        $this->authorize('update', $h);
        $this->run(fn () => app(HandoverWorkflowAction::class)->markDocumentsReady($h, auth()->user()), 'Documents marked ready.');
    }

    public function completeHandover(): void
    {
        $h = $this->booking->documentHandover()->firstOrFail();
        $this->authorize('complete', $h);
        $this->validate([
            'handoverDate' => ['required', 'date'],
            'handoverReceivedBy' => ['required', 'string', 'max:255'],
            'handoverAck' => ['nullable', 'file', 'max:'.config('registry.uploads.max_kb'), 'mimes:'.implode(',', config('registry.uploads.mimes'))],
        ]);

        try {
            app(HandoverWorkflowAction::class)->complete($h, [
                'handover_date' => $this->handoverDate,
                'received_by' => $this->handoverReceivedBy,
                'notes' => $this->handoverNotes ?: null,
            ], auth()->user(), $this->handoverAck);
            $this->reset('showHandoverComplete', 'handoverReceivedBy', 'handoverNotes', 'handoverAck');
            $this->dispatch('toast', message: 'Documents handed over.', variant: 'success');
        } catch (DomainException $e) {
            $this->dispatch('toast', message: $e->getMessage(), variant: 'danger');
        }
    }

    private function run(callable $fn, string $ok): void
    {
        try {
            $fn();
            $this->dispatch('toast', message: $ok, variant: 'success');
        } catch (DomainException $e) {
            $this->dispatch('toast', message: $e->getMessage(), variant: 'danger');
        }
    }

    public function render(): View
    {
        $booking = $this->booking->fresh([
            'project', 'plot',
            'registryCase.appointments.responsibleUser', 'registryCase.expenses.approvedBy',
            'registryCase.handover.document.currentVersion',
        ]);
        $case = $booking->registryCase;

        $eligibility = app(RegistryEligibilityService::class)->evaluate($booking);
        $expenseTotal = $case
            ? $case->expenses->reduce(fn (Money $c, $e) => $c->plus(Money::of($e->amount)), Money::zero())
            : Money::zero();

        return view('livewire.bookings.booking-registry', [
            'booking' => $booking,
            'case' => $case,
            'eligibility' => $eligibility,
            'handover' => $case?->handover,
            'expenseTotal' => $expenseTotal,
            'expenseTypes' => RegistryExpenseType::options(),
            'users' => User::query()->where('status', 'active')->orderBy('name')->pluck('name', 'id'),
        ])->title("Registry · {$booking->booking_number}");
    }
}
