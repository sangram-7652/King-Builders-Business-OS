<?php

declare(strict_types=1);

use App\Enums\ClearanceCategory;
use App\Enums\PlotStatus;
use App\Models\Booking;
use App\Models\PlotOwnershipHistory;
use App\Models\PossessionActivity;
use App\Models\PossessionCase;
use App\Models\PossessionClearance;
use App\Models\PossessionHandover;
use App\Models\TransferRequest;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

beforeEach(fn () => seedDocumentMasters());

it('creates every M10 table with its key columns', function () {
    foreach ([
        'possession_cases', 'possession_clearances', 'possession_appointments', 'possession_inspections',
        'possession_handovers', 'transfer_requests', 'plot_ownership_history', 'buyer_nominees', 'possession_activities',
    ] as $table) {
        expect(Schema::hasTable($table))->toBeTrue("missing table {$table}");
    }

    expect(Schema::hasColumns('possession_cases', ['case_number', 'booking_id', 'plot_id', 'status', 'eligibility_snapshot', 'certificate_document_id', 'status_before_hold']))->toBeTrue()
        ->and(Schema::hasColumns('possession_clearances', ['possession_case_id', 'category', 'status', 'waiver_reason', 'decided_by']))->toBeTrue()
        ->and(Schema::hasColumns('possession_handovers', ['possession_case_id', 'booking_id', 'status', 'received_by', 'acknowledgement_document_id']))->toBeTrue()
        ->and(Schema::hasColumns('transfer_requests', ['request_number', 'booking_id', 'plot_id', 'transfer_type', 'status', 'current_buyer_id', 'new_buyer_id', 'financial_snapshot']))->toBeTrue()
        ->and(Schema::hasColumns('plot_ownership_history', ['plot_id', 'booking_id', 'buyer_id', 'ownership_type', 'is_primary', 'ownership_percentage', 'started_at', 'ended_at', 'source_type', 'source_id']))->toBeTrue();
});

it('adds POSSESSION_COMPLETED to the plot lifecycle without breaking the map', function () {
    expect(PlotStatus::Booked->canTransitionTo(PlotStatus::PossessionCompleted))->toBeTrue()
        ->and(PlotStatus::Booked->canTransitionTo(PlotStatus::Transferred))->toBeFalse()
        ->and(PlotStatus::PossessionCompleted->isTerminal())->toBeTrue()
        ->and(PlotStatus::Available->canTransitionTo(PlotStatus::PossessionCompleted))->toBeFalse();
});

it('enforces one possession case and one handover per booking / case', function () {
    $booking = Booking::factory()->confirmed()->create();
    PossessionCase::factory()->forBooking($booking)->create();

    expect(fn () => PossessionCase::factory()->forBooking($booking)->create())->toThrow(QueryException::class);

    $case = PossessionCase::where('booking_id', $booking->id)->first();
    PossessionHandover::factory()->create(['possession_case_id' => $case->id, 'booking_id' => $booking->id]);
    expect(fn () => PossessionHandover::factory()->create(['possession_case_id' => $case->id, 'booking_id' => $booking->id]))
        ->toThrow(QueryException::class);
});

it('enforces one clearance row per (case, category)', function () {
    $case = PossessionCase::factory()->create();
    PossessionClearance::factory()->for($case, 'possessionCase')->category(ClearanceCategory::Legal)->create();

    expect(fn () => PossessionClearance::factory()->for($case, 'possessionCase')->category(ClearanceCategory::Legal)->create())
        ->toThrow(QueryException::class);
});

it('enforces unique possession + transfer numbers', function () {
    $c = PossessionCase::factory()->create();
    expect(fn () => PossessionCase::factory()->create(['case_number' => $c->case_number]))->toThrow(QueryException::class);

    $t = TransferRequest::factory()->create();
    expect(fn () => TransferRequest::factory()->create(['request_number' => $t->request_number]))->toThrow(QueryException::class);
});

it('stores ownership percentage as DECIMAL and keeps the timeline append-only', function () {
    expect((new PlotOwnershipHistory)->getCasts()['ownership_percentage'])->toBe('decimal:2')
        ->and(PossessionActivity::UPDATED_AT)->toBeNull()
        ->and((new PossessionActivity)->getAttributes())->not->toHaveKey('updated_at');
});
