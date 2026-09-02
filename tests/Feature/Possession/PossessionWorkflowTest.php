<?php

declare(strict_types=1);

use App\Actions\Possession\InitiatePossessionCaseAction;
use App\Actions\Possession\PossessionCaseWorkflowAction;
use App\Actions\Possession\RecordClearanceAction;
use App\Actions\Possession\RecordInspectionAction;
use App\Actions\Possession\RefreshPossessionEligibilityAction;
use App\Actions\Possession\SchedulePossessionAppointmentAction;
use App\Enums\ClearanceCategory;
use App\Enums\ClearanceStatus;
use App\Enums\InspectionStatus;
use App\Enums\PossessionCaseStatus;
use App\Enums\RegistryCaseStatus;
use App\Exceptions\DomainException;
use App\Models\PlotOwnershipHistory;
use App\Models\PossessionCase;
use App\Models\RegistryCase;
use App\Services\Possession\PossessionChecklistService;
use App\Services\Possession\PossessionEligibilityService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    seedRbac();
    Storage::fake('documents');
});

/*
| POSSESSION (1-14)
*/

it('reports a booking as eligible when every prerequisite is met (1)', function () {
    $s = possessionReadyScenario();

    $result = app(PossessionEligibilityService::class)->evaluate($s['booking']->fresh());

    expect($result->eligible)->toBeTrue()
        ->and($result->reasons())->toBe([]);
});

it('is not eligible until the registry case is completed (2)', function () {
    $s = registryReadyScenario();
    config()->set('possession.eligibility.required_paid_percent', 0);

    // no registry case yet
    expect(app(PossessionEligibilityService::class)->evaluate($s['booking']->fresh())->eligible)->toBeFalse();
});

it('opens a READY possession case, seeds clearances and materialises ownership (3)', function () {
    $s = possessionReadyScenario();

    $case = app(InitiatePossessionCaseAction::class)->handle($s['booking']->fresh(), possessionOfficer());

    expect($case->status)->toBe(PossessionCaseStatus::Ready)
        ->and($case->case_number)->toStartWith('POS-')
        ->and($case->clearances()->count())->toBe(4)
        ->and(PlotOwnershipHistory::where('booking_id', $s['booking']->id)->whereNull('ended_at')->count())->toBe(1);
});

it('opens an ELIGIBILITY_PENDING case when not yet eligible', function () {
    $s = registryReadyScenario();
    config()->set('possession.eligibility.required_paid_percent', 0);

    $case = app(InitiatePossessionCaseAction::class)->handle($s['booking']->fresh(), possessionOfficer());
    expect($case->status)->toBe(PossessionCaseStatus::EligibilityPending);
});

it('opens exactly one possession case per booking, even under a race (4)', function () {
    $s = possessionReadyScenario();
    $officer = possessionOfficer();

    $a = app(InitiatePossessionCaseAction::class)->handle($s['booking']->fresh(), $officer);
    $b = app(InitiatePossessionCaseAction::class)->handle($s['booking']->fresh(), $officer);

    expect($b->id)->toBe($a->id)
        ->and(PossessionCase::where('booking_id', $s['booking']->id)->count())->toBe(1);

    expect(fn () => PossessionCase::factory()->create(['booking_id' => $s['booking']->id]))
        ->toThrow(QueryException::class);
});

it('generates distinct possession case numbers (4)', function () {
    $a = app(InitiatePossessionCaseAction::class)->handle(possessionReadyScenario()['booking']->fresh(), possessionOfficer());
    $b = app(InitiatePossessionCaseAction::class)->handle(possessionReadyScenario()['booking']->fresh(), possessionOfficer());

    expect($a->case_number)->not->toBe($b->case_number);
});

it('refreshes eligibility and flips EligibilityPending to Ready', function () {
    $s = registryReadyScenario();
    config()->set('possession.eligibility.required_paid_percent', 0);
    $case = app(InitiatePossessionCaseAction::class)->handle($s['booking']->fresh(), possessionOfficer());
    expect($case->status)->toBe(PossessionCaseStatus::EligibilityPending);

    RegistryCase::factory()->forBooking($s['booking'])->status(RegistryCaseStatus::Completed)->create();

    $case = app(RefreshPossessionEligibilityAction::class)->handle($case->fresh(), possessionOfficer());
    expect($case->status)->toBe(PossessionCaseStatus::Ready);
});

it('reflects the checklist as clearances + inspection are satisfied (5)', function () {
    $s = possessionReadyScenario();
    $officer = possessionOfficer();
    $case = app(InitiatePossessionCaseAction::class)->handle($s['booking']->fresh(), $officer);

    $checklist = app(PossessionChecklistService::class)->for($case);
    expect($checklist->isComplete())->toBeFalse();

    foreach ($case->clearances as $c) {
        app(RecordClearanceAction::class)->handle($case, $c->category, ClearanceStatus::Cleared, $officer);
    }
    app(SchedulePossessionAppointmentAction::class)->handle($case->fresh(), [
        'scheduled_at' => now()->addWeek()->toDateTimeString(), 'site_location' => 'Site',
    ], $officer);
    app(RecordInspectionAction::class)->handle($case->fresh(), [
        'status' => InspectionStatus::Passed->value, 'inspection_date' => now()->toDateString(),
    ], $officer);

    expect(app(PossessionChecklistService::class)->for($case->fresh())->isComplete())->toBeTrue();
});

it('reads M7/M8 truth for financial clearance and never waives automatically (6)', function () {
    $s = possessionReadyScenario('1000000');
    $officer = possessionOfficer();
    $case = app(InitiatePossessionCaseAction::class)->handle($s['booking']->fresh(), $officer);

    // outstanding is the full amount → cannot CLEAR, but no auto-waive
    config()->set('possession.financial_clearance.max_outstanding', '0');
    expect(fn () => app(RecordClearanceAction::class)->handle($case, ClearanceCategory::Financial, ClearanceStatus::Cleared, $officer))
        ->toThrow(DomainException::class);

    // waive needs a reason
    expect(fn () => app(RecordClearanceAction::class)->handle($case, ClearanceCategory::Financial, ClearanceStatus::Waived, $officer))
        ->toThrow(DomainException::class);

    $clearance = app(RecordClearanceAction::class)->handle($case, ClearanceCategory::Financial, ClearanceStatus::Waived, $officer, ['waiver_reason' => 'Board approved']);
    expect($clearance->status)->toBe(ClearanceStatus::Waived)
        ->and($clearance->waiver_reason)->toBe('Board approved')
        ->and($clearance->snapshot['outstanding'])->toBe('1000000.00');
});

it('schedules a READY case and requires a site (7)', function () {
    $s = possessionReadyScenario();
    $officer = possessionOfficer();
    $case = app(InitiatePossessionCaseAction::class)->handle($s['booking']->fresh(), $officer);

    expect(fn () => app(SchedulePossessionAppointmentAction::class)->handle($case, [
        'scheduled_at' => now()->addWeek()->toDateTimeString(), 'site_location' => '',
    ], $officer))->toThrow(DomainException::class);

    $case = app(SchedulePossessionAppointmentAction::class)->handle($case, [
        'scheduled_at' => now()->addWeek()->toDateTimeString(), 'site_location' => 'SRO grounds',
    ], $officer);

    expect($case->status)->toBe(PossessionCaseStatus::Scheduled)
        ->and($case->site_location)->toBe('SRO grounds')
        ->and($case->appointments()->count())->toBe(1);
});

it('reschedules by superseding the live appointment and keeping history (8)', function () {
    $s = scheduledPossessionScenario();
    $officer = possessionOfficer();

    $case = app(SchedulePossessionAppointmentAction::class)->handle($s['case']->fresh(), [
        'scheduled_at' => now()->addWeeks(2)->toDateTimeString(), 'site_location' => 'New site', 'reason' => 'Rain',
    ], $officer, reschedule: true);

    expect($case->appointments()->count())->toBe(2)
        ->and($case->liveAppointment->site_location)->toBe('New site')
        ->and($case->appointments()->whereNotNull('superseded_at')->count())->toBe(1);
});

it('records a passed inspection (9) and a failed one blocks readiness (10, 11)', function () {
    $s = possessionReadyScenario();
    $officer = possessionOfficer();
    $case = app(InitiatePossessionCaseAction::class)->handle($s['booking']->fresh(), $officer);
    foreach ($case->clearances as $c) {
        app(RecordClearanceAction::class)->handle($case, $c->category, ClearanceStatus::Cleared, $officer);
    }
    app(SchedulePossessionAppointmentAction::class)->handle($case->fresh(), [
        'scheduled_at' => now()->addWeek()->toDateTimeString(), 'site_location' => 'Site',
    ], $officer);

    app(RecordInspectionAction::class)->handle($case->fresh(), [
        'status' => InspectionStatus::Failed->value, 'inspection_date' => now()->toDateString(), 'remarks' => 'Boundary dispute',
    ], $officer);

    expect(fn () => app(PossessionCaseWorkflowAction::class)->markReadyForHandover($case->fresh(), $officer))
        ->toThrow(DomainException::class);

    // re-inspection passes → now allowed
    app(RecordInspectionAction::class)->handle($case->fresh(), [
        'status' => InspectionStatus::Passed->value, 'inspection_date' => now()->toDateString(),
    ], $officer);

    $case = app(PossessionCaseWorkflowAction::class)->markReadyForHandover($case->fresh(), $officer);
    expect($case->status)->toBe(PossessionCaseStatus::ReadyForHandover)
        ->and($case->handover)->not->toBeNull();
});

it('cannot mark ready for handover while the checklist is incomplete', function () {
    $s = scheduledPossessionScenario();
    $officer = possessionOfficer();

    // reject a clearance so the checklist fails
    app(RecordClearanceAction::class)->handle($s['case'], ClearanceCategory::Legal, ClearanceStatus::Rejected, $officer);

    expect(fn () => app(PossessionCaseWorkflowAction::class)->markReadyForHandover($s['case']->fresh(), $officer))
        ->toThrow(DomainException::class);
});

it('puts a case on hold and resumes it to the prior status', function () {
    $s = scheduledPossessionScenario();
    $officer = possessionOfficer();

    $case = app(PossessionCaseWorkflowAction::class)->putOnHold($s['case'], 'Awaiting docs', $officer);
    expect($case->status)->toBe(PossessionCaseStatus::OnHold)
        ->and($case->status_before_hold)->toBe(PossessionCaseStatus::Inspection->value);

    $case = app(PossessionCaseWorkflowAction::class)->resume($case->fresh(), $officer);
    expect($case->status)->toBe(PossessionCaseStatus::Inspection);
});
