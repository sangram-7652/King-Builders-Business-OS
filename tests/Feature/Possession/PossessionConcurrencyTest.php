<?php

declare(strict_types=1);

use App\Actions\Possession\InitiatePossessionCaseAction;
use App\Actions\Possession\PossessionCaseWorkflowAction;
use App\Actions\Possession\PossessionHandoverAction;
use App\Actions\Transfer\CompleteTransferAction;
use App\Actions\Transfer\CreateTransferRequestAction;
use App\Actions\Transfer\TransferWorkflowAction;
use App\Enums\PossessionCaseStatus;
use App\Enums\TransferRequestStatus;
use App\Enums\TransferType;
use App\Exceptions\DomainException;
use App\Models\Booking;
use App\Models\Buyer;
use App\Models\PlotOwnershipHistory;
use App\Models\PossessionCase;
use App\Models\PossessionHandover;
use App\Models\TransferRequest;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    seedRbac();
    Storage::fake('documents');
});

/*
| CONCURRENCY (37-39)
*/

it('completes a possession handover only once under repeated calls (37)', function () {
    $s = scheduledPossessionScenario();
    $officer = possessionOfficer();
    $case = app(PossessionCaseWorkflowAction::class)->markReadyForHandover($s['case']->fresh(), $officer);
    $handover = $case->handover;

    app(PossessionHandoverAction::class)->startHandover($handover, ['handover_date' => now()->toDateString(), 'received_by' => 'A'], $officer);
    app(PossessionHandoverAction::class)->recordAcknowledgement($handover->fresh(), $officer);

    app(PossessionHandoverAction::class)->complete($handover->fresh(), $officer);
    app(PossessionHandoverAction::class)->complete($handover->fresh(), $officer);

    expect(PossessionHandover::where('possession_case_id', $case->id)->where('status', 'completed')->count())->toBe(1)
        ->and(PossessionCase::whereKey($case->id)->first()->status)->toBe(PossessionCaseStatus::Completed);
});

it('locks the possession case row FOR UPDATE while completing a handover', function () {
    $s = scheduledPossessionScenario();
    $officer = possessionOfficer();
    $case = app(PossessionCaseWorkflowAction::class)->markReadyForHandover($s['case']->fresh(), $officer);
    $handover = $case->handover;
    app(PossessionHandoverAction::class)->startHandover($handover, ['handover_date' => now()->toDateString(), 'received_by' => 'A'], $officer);
    app(PossessionHandoverAction::class)->recordAcknowledgement($handover->fresh(), $officer);

    $sql = [];
    DB::listen(fn ($q) => $sql[] = strtolower($q->sql));
    app(PossessionHandoverAction::class)->complete($handover->fresh(), $officer);

    expect(collect($sql)->contains(fn (string $q) => str_contains($q, 'for update')))->toBeTrue();
})->skip(fn () => DB::connection()->getDriverName() === 'sqlite', 'SQLite has no row-level FOR UPDATE');

it('completes only one of two competing approved transfers (38, 39)', function () {
    $s = transferReadyScenario();

    $t1 = app(CreateTransferRequestAction::class)->handle($s['booking']->fresh(), TransferType::SaleTransfer, ['new_buyer_id' => $s['newBuyer']->id], possessionOfficer());
    $officer = possessionOfficer();
    app(TransferWorkflowAction::class)->submit($t1, $officer);
    app(TransferWorkflowAction::class)->startReview($t1->fresh(), $officer);
    app(TransferWorkflowAction::class)->approve($t1->fresh(), $officer);

    // a second approved transfer forced past the "one open" guard
    $t2 = TransferRequest::factory()->forBooking($s['booking'])->status(TransferRequestStatus::Approved)->create([
        'new_buyer_id' => Buyer::factory()->create(['status' => 'active'])->id,
        'current_buyer_id' => $s['buyer']->id,
        'transfer_type' => TransferType::SaleTransfer->value,
        'approved_at' => now()->subMinute(),
    ]);

    app(CompleteTransferAction::class)->handle($t1->fresh(), $officer);

    expect(fn () => app(CompleteTransferAction::class)->handle($t2->fresh(), $officer))
        ->toThrow(DomainException::class);

    expect(PlotOwnershipHistory::where('booking_id', $s['booking']->id)->whereNull('ended_at')->count())->toBe(1)
        ->and(TransferRequest::where('booking_id', $s['booking']->id)->where('status', 'completed')->count())->toBe(1);
});

it('protects the possession + transfer sequence numbers against a raw duplicate (39)', function () {
    $s = possessionReadyScenario();
    $case = app(InitiatePossessionCaseAction::class)->handle($s['booking']->fresh(), possessionOfficer());

    expect(fn () => PossessionCase::factory()->create(['case_number' => $case->case_number, 'booking_id' => Booking::factory()->confirmed()]))
        ->toThrow(QueryException::class);
});
