<?php

declare(strict_types=1);

use App\Actions\Registry\ApproveRegistryExpenseAction;
use App\Actions\Registry\InitiateRegistryCaseAction;
use App\Actions\Registry\RecordRegistryExpenseAction;
use App\Actions\Registry\RefreshRegistryEligibilityAction;
use App\Actions\Registry\RegistryCaseWorkflowAction;
use App\Actions\Registry\ScheduleRegistryAppointmentAction;
use App\Enums\RegistryCaseStatus;
use App\Enums\RegistryExpenseType;
use App\Exceptions\DomainException;
use App\Models\Agreement;
use App\Models\Document;
use App\Models\DocumentHandover;
use App\Models\RegistryCase;
use App\Models\RegistryExpense;
use App\Services\Payments\PaymentLedger;
use App\Services\Registry\RegistryEligibilityService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    seedRbac();
    Storage::fake('documents');
});

/*
| ELIGIBILITY (24-27)
*/

it('reports a booking as eligible when every prerequisite is met (24)', function () {
    $s = registryReadyScenario();

    $result = app(RegistryEligibilityService::class)->evaluate($s['booking']->fresh());

    expect($result->eligible)->toBeTrue()
        ->and($result->reasons())->toBe([]);
});

it('is not eligible while required documents are unverified (25)', function () {
    $s = registryReadyScenario();
    $s['buyer']->documents()->update(['status' => 'uploaded']);

    $result = app(RegistryEligibilityService::class)->evaluate($s['booking']->fresh());

    expect($result->eligible)->toBeFalse()
        ->and($result->reasons())->not->toBe([]);
});

it('is not eligible without a signed agreement (26)', function () {
    $s = registryReadyScenario();
    $s['booking']->agreement()->update(['status' => 'draft']);

    expect(app(RegistryEligibilityService::class)->evaluate($s['booking']->fresh())->eligible)->toBeFalse();
});

it('is not eligible with an overdue balance (27)', function () {
    $s = registryReadyScenario('1000000');
    activePlanFor($s['booking'], $s['actor'], [
        ['type' => 'amount', 'value' => '500000', 'due_date' => now()->subDays(40)->toDateString()],
        ['type' => 'amount', 'value' => '500000', 'due_date' => now()->addDays(40)->toDateString()],
    ]);

    $result = app(RegistryEligibilityService::class)->evaluate($s['booking']->fresh());
    expect($result->eligible)->toBeFalse();
});

it('is not eligible below the required collected percentage', function () {
    $s = registryReadyScenario('1000000');
    config()->set('registry.eligibility.required_paid_percent', 90);

    expect(app(RegistryEligibilityService::class)->evaluate($s['booking']->fresh())->eligible)->toBeFalse();
});

/*
| REGISTRY CASE (28-33)
*/

it('opens a READY case when the booking is eligible (28)', function () {
    $s = registryReadyScenario();

    $case = app(InitiateRegistryCaseAction::class)->handle($s['booking']->fresh(), registryOfficer());

    expect($case->status)->toBe(RegistryCaseStatus::Ready)
        ->and($case->case_number)->toStartWith('REG-')
        ->and($case->eligibility_snapshot['eligible'])->toBeTrue();
});

it('opens an ELIGIBILITY_PENDING case when not yet eligible (29)', function () {
    $s = confirmedBookingScenario();
    seedDocumentMasters();

    $case = app(InitiateRegistryCaseAction::class)->handle($s['booking']->fresh(), registryOfficer());
    expect($case->status)->toBe(RegistryCaseStatus::EligibilityPending);
});

it('opens exactly one registry case per booking, even under a race (30)', function () {
    $s = registryReadyScenario();

    $a = app(InitiateRegistryCaseAction::class)->handle($s['booking']->fresh(), registryOfficer());
    $b = app(InitiateRegistryCaseAction::class)->handle($s['booking']->fresh(), registryOfficer());

    expect($b->id)->toBe($a->id)
        ->and(RegistryCase::where('booking_id', $s['booking']->id)->count())->toBe(1);

    expect(fn () => RegistryCase::factory()->create(['booking_id' => $s['booking']->id]))
        ->toThrow(QueryException::class);
});

it('generates distinct registry case numbers (31)', function () {
    $a = app(InitiateRegistryCaseAction::class)->handle(registryReadyScenario()['booking']->fresh(), registryOfficer());
    $b = app(InitiateRegistryCaseAction::class)->handle(registryReadyScenario()['booking']->fresh(), registryOfficer());

    expect($a->case_number)->not->toBe($b->case_number);
});

it('refreshes eligibility and flips EligibilityPending to Ready (32)', function () {
    $s = confirmedBookingScenario();
    seedDocumentMasters();
    $case = app(InitiateRegistryCaseAction::class)->handle($s['booking']->fresh(), registryOfficer());
    expect($case->status)->toBe(RegistryCaseStatus::EligibilityPending);

    // now satisfy everything
    config()->set('registry.eligibility.required_paid_percent', 0);
    foreach (['AADHAAR', 'PAN', 'ADDRESS_PROOF', 'PHOTO'] as $c) {
        Document::factory()->verified()->forDocumentable($s['buyer'])->state(['document_type_id' => docType($c)->id])->create();
    }
    foreach (['BOOKING_FORM', 'BOOKING_AGREEMENT'] as $c) {
        Document::factory()->verified()->forDocumentable($s['booking'])->state(['document_type_id' => docType($c)->id])->create();
    }
    Agreement::factory()->signed()->create(['booking_id' => $s['booking']->id]);

    $case = app(RefreshRegistryEligibilityAction::class)->handle($case->fresh(), registryOfficer());
    expect($case->status)->toBe(RegistryCaseStatus::Ready);
});

it('never downgrades a scheduled case during an eligibility refresh (33)', function () {
    $s = registryReadyScenario();
    $case = RegistryCase::factory()->forBooking($s['booking'])->status(RegistryCaseStatus::Scheduled)->create();
    $s['booking']->agreement()->update(['status' => 'draft']); // eligibility now fails

    $case = app(RefreshRegistryEligibilityAction::class)->handle($case->fresh(), registryOfficer());
    expect($case->status)->toBe(RegistryCaseStatus::Scheduled);
});

/*
| APPOINTMENT + WORKFLOW
*/

it('schedules an appointment for a READY case and requires an office', function () {
    $s = registryReadyScenario();
    $case = app(InitiateRegistryCaseAction::class)->handle($s['booking']->fresh(), registryOfficer());

    expect(fn () => app(ScheduleRegistryAppointmentAction::class)->handle($case, [
        'scheduled_at' => now()->addWeek()->toDateTimeString(), 'registry_office' => '',
    ], registryOfficer()))->toThrow(DomainException::class);

    $case = app(ScheduleRegistryAppointmentAction::class)->handle($case, [
        'scheduled_at' => now()->addWeek()->toDateTimeString(),
        'registry_office' => 'SRO Jaipur-II',
    ], registryOfficer());

    expect($case->status)->toBe(RegistryCaseStatus::Scheduled)
        ->and($case->registry_office)->toBe('SRO Jaipur-II')
        ->and($case->appointments()->count())->toBe(1);
});

it('reschedules by superseding the live appointment and keeping history', function () {
    $s = registryReadyScenario();
    $case = app(InitiateRegistryCaseAction::class)->handle($s['booking']->fresh(), registryOfficer());
    $case = app(ScheduleRegistryAppointmentAction::class)->handle($case, [
        'scheduled_at' => now()->addWeek()->toDateTimeString(), 'registry_office' => 'SRO A',
    ], registryOfficer());

    $case = app(ScheduleRegistryAppointmentAction::class)->handle($case->fresh(), [
        'scheduled_at' => now()->addWeeks(2)->toDateTimeString(), 'registry_office' => 'SRO B',
        'reason' => 'Officer unavailable',
    ], registryOfficer(), reschedule: true);

    expect($case->appointments()->count())->toBe(2)
        ->and($case->liveAppointment->registry_office)->toBe('SRO B')
        ->and($case->appointments()->whereNotNull('superseded_at')->count())->toBe(1);
});

it('walks a case scheduled → in process → completed and opens the handover', function () {
    $s = registryReadyScenario();
    $case = app(InitiateRegistryCaseAction::class)->handle($s['booking']->fresh(), registryOfficer());
    $case = app(ScheduleRegistryAppointmentAction::class)->handle($case, [
        'scheduled_at' => now()->addWeek()->toDateTimeString(), 'registry_office' => 'SRO A',
    ], registryOfficer());

    $case = app(RegistryCaseWorkflowAction::class)->markInProcess($case->fresh(), registryOfficer());
    expect($case->status)->toBe(RegistryCaseStatus::InProcess);

    $case = app(RegistryCaseWorkflowAction::class)->complete($case->fresh(), [
        'registered_document_number' => 'REG/2026/00123',
        'registration_date' => now()->toDateString(),
    ], registryOfficer(), fakeDocument('deed.pdf'));

    expect($case->status)->toBe(RegistryCaseStatus::Completed)
        ->and($case->registered_document_number)->toBe('REG/2026/00123')
        ->and($case->handover)->not->toBeNull();
});

it('requires a registered document number to complete and is idempotent', function () {
    $s = registryReadyScenario();
    $case = RegistryCase::factory()->forBooking($s['booking'])->status(RegistryCaseStatus::Scheduled)->create();

    expect(fn () => app(RegistryCaseWorkflowAction::class)->complete($case, [
        'registered_document_number' => '  ', 'registration_date' => now()->toDateString(),
    ], registryOfficer()))->toThrow(DomainException::class);

    $data = ['registered_document_number' => 'RD-1', 'registration_date' => now()->toDateString()];
    $first = app(RegistryCaseWorkflowAction::class)->complete($case->fresh(), $data, registryOfficer());
    $again = app(RegistryCaseWorkflowAction::class)->complete($case->fresh(), $data, registryOfficer());

    expect($again->id)->toBe($first->id)
        ->and(DocumentHandover::where('booking_id', $s['booking']->id)->count())->toBe(1);
});

it('puts a case on hold and resumes it to the prior status', function () {
    $s = registryReadyScenario();
    $case = RegistryCase::factory()->forBooking($s['booking'])->status(RegistryCaseStatus::Scheduled)->create();

    $case = app(RegistryCaseWorkflowAction::class)->putOnHold($case, 'Awaiting NOC', registryOfficer());
    expect($case->status)->toBe(RegistryCaseStatus::OnHold)
        ->and($case->status_before_hold)->toBe(RegistryCaseStatus::Scheduled->value);

    $case = app(RegistryCaseWorkflowAction::class)->resume($case->fresh(), registryOfficer());
    expect($case->status)->toBe(RegistryCaseStatus::Scheduled);
});

it('cannot cancel a completed registry case', function () {
    $s = registryReadyScenario();
    $case = RegistryCase::factory()->forBooking($s['booking'])->status(RegistryCaseStatus::Completed)->create();

    expect(fn () => app(RegistryCaseWorkflowAction::class)->cancel($case, 'nope', registryOfficer()))
        ->toThrow(DomainException::class);
});

it('gates registry completion on registry.complete', function () {
    $s = registryReadyScenario();
    $case = RegistryCase::factory()->forBooking($s['booking'])->status(RegistryCaseStatus::Scheduled)->create();

    $noComplete = makeUser(permissions: ['registry.view', 'registry.update', 'bookings.view']);
    expect(fn () => app(RegistryCaseWorkflowAction::class)->complete($case, [
        'registered_document_number' => 'RD-9', 'registration_date' => now()->toDateString(),
    ], $noComplete))->toThrow(DomainException::class);
});

/*
| EXPENSES (34-36)
*/

it('records a registry expense as DECIMAL and never posts it to the booking (34)', function () {
    $s = registryReadyScenario('1000000');
    $case = RegistryCase::factory()->forBooking($s['booking'])->create();
    $ledgerPaidBefore = app(PaymentLedger::class)->bookingPaid($s['booking'])->store();

    $expense = app(RecordRegistryExpenseAction::class)->handle($case, [
        'expense_type' => RegistryExpenseType::StampDuty->value,
        'amount' => '59999.99',
    ], registryOfficer());

    expect($expense->amount)->toBe('59999.99')
        ->and($expense->status)->toBe(RegistryExpense::STATUS_RECORDED)
        ->and($s['booking']->fresh()->final_amount)->toBe('1000000.00')
        ->and(app(PaymentLedger::class)->bookingPaid($s['booking']->fresh())->store())->toBe($ledgerPaidBefore);
});

it('rejects a zero or negative expense amount (35)', function () {
    $s = registryReadyScenario();
    $case = RegistryCase::factory()->forBooking($s['booking'])->create();

    expect(fn () => app(RecordRegistryExpenseAction::class)->handle($case, [
        'expense_type' => RegistryExpenseType::Processing->value, 'amount' => '0',
    ], registryOfficer()))->toThrow(DomainException::class);
});

it('approves a recorded expense once (idempotent) and gates on the permission (36)', function () {
    $s = registryReadyScenario();
    $case = RegistryCase::factory()->forBooking($s['booking'])->create();
    $expense = app(RecordRegistryExpenseAction::class)->handle($case, [
        'expense_type' => RegistryExpenseType::RegistrationFee->value, 'amount' => '25000',
    ], registryOfficer());

    $noApprove = makeUser(permissions: ['registry_expenses.view', 'registry_expenses.create', 'bookings.view']);
    expect(fn () => app(ApproveRegistryExpenseAction::class)->handle($expense, $noApprove))
        ->toThrow(DomainException::class);

    $expense = app(ApproveRegistryExpenseAction::class)->handle($expense, registryOfficer());
    expect($expense->status)->toBe(RegistryExpense::STATUS_APPROVED);

    $again = app(ApproveRegistryExpenseAction::class)->handle($expense->fresh(), registryOfficer());
    expect($again->approved_at->equalTo($expense->approved_at))->toBeTrue();
});
