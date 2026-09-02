<?php

declare(strict_types=1);

namespace App\Livewire\Bookings;

use App\Actions\Possession\GeneratePossessionCertificateAction;
use App\Actions\Possession\InitiatePossessionCaseAction;
use App\Actions\Possession\PossessionCaseWorkflowAction;
use App\Actions\Possession\PossessionHandoverAction;
use App\Actions\Possession\RecordClearanceAction;
use App\Actions\Possession\RecordInspectionAction;
use App\Actions\Possession\RefreshPossessionEligibilityAction;
use App\Actions\Possession\SchedulePossessionAppointmentAction;
use App\Enums\ClearanceCategory;
use App\Enums\ClearanceStatus;
use App\Enums\InspectionStatus;
use App\Exceptions\DomainException;
use App\Models\Booking;
use App\Models\PossessionCase;
use App\Services\Possession\PossessionChecklistService;
use App\Services\Possession\PossessionEligibilityService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\UploadedFile;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;

#[Layout('components.layouts.app')]
class BookingPossession extends Component
{
    use WithFileUploads;

    public Booking $booking;

    // schedule
    public bool $showSchedule = false;

    public bool $rescheduleMode = false;

    public string $scheduledAt = '';

    public string $siteLocation = '';

    public string $scheduleNotes = '';

    public string $scheduleReason = '';

    // clearance
    public ?string $clearingCategory = null;

    public string $clearanceRemarks = '';

    public string $waiverReason = '';

    // inspection
    public bool $showInspection = false;

    public string $inspectionStatus = 'passed';

    public string $inspectionDate = '';

    public string $inspectionRemarks = '';

    /** @var UploadedFile|null */
    public $inspectionReport = null;

    // handover
    public bool $showHandover = false;

    public string $handoverDate = '';

    public string $handoverReceivedBy = '';

    public string $handoverReceiverIdentity = '';

    public string $handoverReceiverRelation = '';

    public string $handoverRemarks = '';

    /** @var UploadedFile|null */
    public $handoverAck = null;

    // hold
    public bool $showHold = false;

    public string $holdReason = '';

    public function mount(Booking $booking): void
    {
        $this->authorize('viewAny', PossessionCase::class);
        $this->authorize('view', $booking);
        abort_unless($booking->isConfirmed(), 404);
        $this->booking = $booking;
        $this->inspectionDate = now()->toDateString();
        $this->handoverDate = now()->toDateString();
        $this->scheduledAt = now()->addWeek()->format('Y-m-d\TH:i');
    }

    private function case(): PossessionCase
    {
        return $this->booking->possessionCase()->firstOrFail();
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

    public function initiate(): void
    {
        $this->authorize('create', PossessionCase::class);
        $this->run(fn () => app(InitiatePossessionCaseAction::class)->handle($this->booking, auth()->user()), 'Possession case opened.');
    }

    public function refreshEligibility(): void
    {
        $case = $this->case();
        $this->authorize('update', $case);
        $this->run(fn () => app(RefreshPossessionEligibilityAction::class)->handle($case, auth()->user()), 'Eligibility refreshed.');
    }

    public function recordClearance(string $category, string $decision): void
    {
        $case = $this->case();
        $this->authorize('clear', $case);
        $this->run(function () use ($case, $category, $decision): void {
            app(RecordClearanceAction::class)->handle(
                $case,
                ClearanceCategory::from($category),
                ClearanceStatus::from($decision),
                auth()->user(),
                ['remarks' => $this->clearanceRemarks ?: null, 'waiver_reason' => $this->waiverReason ?: null],
            );
            $this->reset('clearingCategory', 'clearanceRemarks', 'waiverReason');
        }, 'Clearance updated.');
    }

    public function schedule(bool $reschedule = false): void
    {
        $case = $this->case();
        $this->authorize('schedule', $case);
        $this->validate([
            'scheduledAt' => ['required', 'date'],
            'siteLocation' => ['required', 'string', 'max:255'],
        ]);
        $this->run(function () use ($case, $reschedule): void {
            app(SchedulePossessionAppointmentAction::class)->handle($case, [
                'scheduled_at' => $this->scheduledAt,
                'site_location' => $this->siteLocation,
                'notes' => $this->scheduleNotes ?: null,
                'reason' => $this->scheduleReason ?: null,
            ], auth()->user(), $reschedule);
            $this->reset('showSchedule', 'rescheduleMode', 'scheduleNotes', 'scheduleReason');
        }, 'Possession appointment saved.');
    }

    public function recordInspection(): void
    {
        $case = $this->case();
        $this->authorize('inspect', $case);
        $this->validate([
            'inspectionStatus' => ['required'],
            'inspectionDate' => ['required', 'date'],
            'inspectionReport' => ['nullable', 'file', 'max:'.config('possession.uploads.max_kb'), 'mimes:'.implode(',', config('possession.uploads.mimes'))],
        ]);
        $this->run(function () use ($case): void {
            app(RecordInspectionAction::class)->handle($case, [
                'status' => $this->inspectionStatus,
                'inspection_date' => $this->inspectionDate,
                'remarks' => $this->inspectionRemarks ?: null,
            ], auth()->user(), $this->inspectionReport);
            $this->reset('showInspection', 'inspectionRemarks', 'inspectionReport');
        }, 'Inspection recorded.');
    }

    public function markReadyForHandover(): void
    {
        $case = $this->case();
        $this->authorize('complete', $case);
        $this->run(fn () => app(PossessionCaseWorkflowAction::class)->markReadyForHandover($case, auth()->user()), 'Possession is ready for handover.');
    }

    public function startHandover(): void
    {
        $case = $this->case();
        $this->authorize('complete', $case);
        $this->validate([
            'handoverDate' => ['required', 'date'],
            'handoverReceivedBy' => ['required', 'string', 'max:255'],
            'handoverAck' => ['nullable', 'file', 'max:'.config('possession.uploads.max_kb'), 'mimes:'.implode(',', config('possession.uploads.mimes'))],
        ]);
        $this->run(function () use ($case): void {
            $handover = $case->handover()->firstOrFail();
            app(PossessionHandoverAction::class)->startHandover($handover, [
                'handover_date' => $this->handoverDate,
                'received_by' => $this->handoverReceivedBy,
                'receiver_identity' => $this->handoverReceiverIdentity ?: null,
                'receiver_relation' => $this->handoverReceiverRelation ?: null,
                'remarks' => $this->handoverRemarks ?: null,
            ], auth()->user());
            app(PossessionHandoverAction::class)->recordAcknowledgement($handover->fresh(), auth()->user(), $this->handoverAck);
            $this->reset('showHandover', 'handoverAck');
        }, 'Handover recorded — acknowledge and complete next.');
    }

    public function completeHandover(): void
    {
        $case = $this->case();
        $this->authorize('complete', $case);
        $this->run(function () use ($case): void {
            app(PossessionHandoverAction::class)->complete($case->handover()->firstOrFail(), auth()->user());
        }, 'Possession completed.');
    }

    public function generateCertificate(): void
    {
        $case = $this->case();
        $this->authorize('complete', $case);
        $this->run(fn () => app(GeneratePossessionCertificateAction::class)->handle($case, auth()->user()), 'Possession certificate generated.');
    }

    public function putOnHold(): void
    {
        $case = $this->case();
        $this->authorize('update', $case);
        $this->validate(['holdReason' => ['required', 'string', 'min:3', 'max:255']]);
        $this->run(function () use ($case): void {
            app(PossessionCaseWorkflowAction::class)->putOnHold($case, $this->holdReason, auth()->user());
            $this->reset('showHold', 'holdReason');
        }, 'Possession put on hold.');
    }

    public function resume(): void
    {
        $case = $this->case();
        $this->authorize('update', $case);
        $this->run(fn () => app(PossessionCaseWorkflowAction::class)->resume($case, auth()->user()), 'Possession resumed.');
    }

    public function cancelCase(): void
    {
        $case = $this->case();
        $this->authorize('update', $case);
        $this->run(fn () => app(PossessionCaseWorkflowAction::class)->cancel($case, 'Cancelled from possession screen', auth()->user()), 'Possession case cancelled.');
    }

    public function render(): View
    {
        $case = $this->booking->possessionCase()
            ->with(['clearances', 'liveAppointment', 'appointments', 'inspections.inspectedBy', 'handover', 'certificateDocument.versions', 'assignedTo'])
            ->first();

        $eligibility = app(PossessionEligibilityService::class)->evaluate($this->booking);
        $checklist = $case ? app(PossessionChecklistService::class)->for($case) : null;

        return view('livewire.bookings.booking-possession', [
            'booking' => $this->booking,
            'case' => $case,
            'eligibility' => $eligibility,
            'checklist' => $checklist,
            'clearanceCategories' => ClearanceCategory::cases(),
            'inspectionStatuses' => InspectionStatus::options(),
        ])->title("Possession · {$this->booking->booking_number}");
    }
}
