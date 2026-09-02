<?php

declare(strict_types=1);

use App\Actions\Possession\InitiatePossessionCaseAction;
use App\Actions\Possession\RecordClearanceAction;
use App\Actions\Possession\RecordInspectionAction;
use App\Actions\Transfer\CompleteTransferAction;
use App\Actions\Transfer\CreateTransferRequestAction;
use App\Enums\ClearanceCategory;
use App\Enums\ClearanceStatus;
use App\Enums\InspectionStatus;
use App\Enums\RoleName;
use App\Enums\TransferRequestStatus;
use App\Enums\TransferType;
use App\Exceptions\DomainException;
use App\Models\PossessionCase;
use App\Models\TransferRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    seedRbac();
    Storage::fake('documents');
});

/*
| SECURITY (30-36)
*/

it('rejects unauthorised possession case creation (30)', function () {
    $s = possessionReadyScenario();

    $noCreate = makeUser(permissions: ['possession.view', 'bookings.view']);
    expect(fn () => app(InitiatePossessionCaseAction::class)->handle($s['booking']->fresh(), $noCreate))
        ->toThrow(DomainException::class);
});

it('rejects unauthorised inspection (31)', function () {
    $s = scheduledPossessionScenario();

    $noInspect = makeUser(permissions: ['possession.view', 'possession.schedule', 'bookings.view']);
    expect(fn () => app(RecordInspectionAction::class)->handle($s['case'], [
        'status' => InspectionStatus::Passed->value, 'inspection_date' => now()->toDateString(),
    ], $noInspect))->toThrow(DomainException::class);
});

it('rejects unauthorised clearance (31)', function () {
    $s = scheduledPossessionScenario();

    $noClear = makeUser(permissions: ['possession.view', 'bookings.view']);
    expect(fn () => app(RecordClearanceAction::class)->handle($s['case'], ClearanceCategory::Legal, ClearanceStatus::Cleared, $noClear))
        ->toThrow(DomainException::class);
});

it('rejects unauthorised transfer creation (32)', function () {
    $s = transferReadyScenario();

    $noCreate = makeUser(permissions: ['transfer.view', 'bookings.view']);
    expect(fn () => app(CreateTransferRequestAction::class)->handle(
        $s['booking']->fresh(), TransferType::SaleTransfer, ['new_buyer_id' => $s['newBuyer']->id], $noCreate,
    ))->toThrow(DomainException::class);
});

it('keeps transfer approval and completion off normal sales roles (33, 34)', function () {
    $sales = makeUser([RoleName::SalesManager->value]);

    expect($sales->can('transfer.approve'))->toBeFalse()
        ->and($sales->can('transfer.complete'))->toBeFalse()
        ->and($sales->can('possession.complete'))->toBeFalse();

    $possession = makeUser([RoleName::PossessionManager->value]);
    expect($possession->can('transfer.approve'))->toBeTrue()
        ->and($possession->can('transfer.complete'))->toBeTrue()
        ->and($possession->can('possession.complete'))->toBeTrue();
});

it('rejects unauthorised transfer completion (34)', function () {
    $s = transferReadyScenario();
    $t = TransferRequest::factory()->forBooking($s['booking'])->status(TransferRequestStatus::Approved)->create([
        'new_buyer_id' => $s['newBuyer']->id, 'current_buyer_id' => $s['buyer']->id, 'approved_at' => now(),
    ]);

    $noComplete = makeUser(permissions: ['transfer.view', 'transfer.review', 'transfer.approve', 'bookings.view']);
    expect(fn () => app(CompleteTransferAction::class)->handle($t, $noComplete))
        ->toThrow(DomainException::class);
});

it('prevents IDOR — a user who cannot view the booking cannot reach its possession case (35)', function () {
    $s = possessionReadyScenario();
    $case = PossessionCase::factory()->forBooking($s['booking'])->create();

    $outsider = makeUser(permissions: ['possession.view', 'possession.complete']); // no bookings.view
    expect($outsider->can('view', $case))->toBeFalse()
        ->and($outsider->can('complete', $case))->toBeFalse();

    $insider = possessionOfficer();
    expect($insider->can('view', $case))->toBeTrue();
});

it('prevents IDOR on transfer requests (35)', function () {
    $s = transferReadyScenario();
    $t = TransferRequest::factory()->forBooking($s['booking'])->create(['new_buyer_id' => $s['newBuyer']->id]);

    $outsider = makeUser(permissions: ['transfer.view', 'transfer.complete']);
    expect($outsider->can('view', $t))->toBeFalse();
});

it('blocks the possession + transfer screens without their view permission (36)', function () {
    $s = possessionReadyScenario();

    $this->actingAs(makeUser(permissions: ['bookings.view']))
        ->get(route('possession.booking', $s['booking']))->assertForbidden();
    $this->actingAs(makeUser(permissions: ['bookings.view']))
        ->get(route('transfers.booking', $s['booking']))->assertForbidden();
});
