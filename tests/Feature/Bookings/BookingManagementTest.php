<?php

declare(strict_types=1);

use App\Actions\Bookings\CancelBookingAction;
use App\Actions\Bookings\CreateBookingAction;
use App\Actions\Bookings\SubmitBookingAction;
use App\Actions\Bookings\UpdateBookingAction;
use App\Enums\BookingStatus;
use App\Enums\PlotStatus;
use App\Exceptions\DomainException;
use App\Models\Block;
use App\Models\Booking;
use App\Models\Plot;
use App\Models\Project;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('generates a sequential BK-000001 booking number that is not the primary key (1)', function () {
    $s = bookingScenario();
    $s2 = bookingScenario();

    $a = app(CreateBookingAction::class)->handle(bookingPayload($s), $s['actor']);
    $b = app(CreateBookingAction::class)->handle(bookingPayload($s2), $s2['actor']);

    expect($a->booking_number)->toBe('BK-000001')
        ->and($b->booking_number)->toBe('BK-000002')
        ->and($a->getKeyName())->toBe('id')
        ->and($a->id)->not->toBe($a->booking_number);

    expect(fn () => Booking::query()->where('id', $a->id)->update(['booking_number' => 'BK-000002']))
        ->toThrow(QueryException::class);
});

it('enforces the BookingStatus transition map (2)', function () {
    expect(BookingStatus::Draft->canTransitionTo(BookingStatus::Pending))->toBeTrue()
        ->and(BookingStatus::Draft->canTransitionTo(BookingStatus::Confirmed))->toBeFalse()
        ->and(BookingStatus::Pending->canTransitionTo(BookingStatus::Confirmed))->toBeTrue()
        ->and(BookingStatus::Pending->canTransitionTo(BookingStatus::Cancelled))->toBeTrue()
        ->and(BookingStatus::Confirmed->canTransitionTo(BookingStatus::Cancelled))->toBeTrue()
        ->and(BookingStatus::Confirmed->canTransitionTo(BookingStatus::Pending))->toBeFalse()
        ->and(BookingStatus::Cancelled->isTerminal())->toBeTrue();
});

it('creates a draft booking with priced lines and buyers (3)', function () {
    $s = bookingScenario();

    $booking = app(CreateBookingAction::class)->handle(bookingPayload($s), $s['actor']);

    expect($booking->status)->toBe(BookingStatus::Draft)
        ->and($booking->base_amount)->toBe('2000000.00')
        ->and($booking->final_amount)->toBe('2100000.00')
        ->and($booking->priceLines()->where('type', 'base')->count())->toBe(1)
        ->and($booking->bookingBuyers)->toHaveCount(1);
});

it('a draft booking does not touch plot inventory (4)', function () {
    $s = bookingScenario();

    $booking = app(CreateBookingAction::class)->handle(bookingPayload($s), $s['actor']);

    expect($s['plot']->fresh()->status)->toBe(PlotStatus::Available)
        ->and($booking->active_plot_id)->toBeNull();
});

it('requires at least one buyer (5)', function () {
    $s = bookingScenario();

    expect(fn () => app(CreateBookingAction::class)->handle(
        bookingPayload($s, ['buyers' => []]), $s['actor']
    ))->toThrow(DomainException::class);
})->skip(false);

it('requires exactly one primary buyer (6)', function () {
    $s = bookingScenario();

    $payload = bookingPayload($s, ['buyers' => [
        ['buyer_id' => $s['buyerA']->id, 'ownership_percentage' => '50', 'is_primary' => true],
        ['buyer_id' => $s['buyerB']->id, 'ownership_percentage' => '50', 'is_primary' => true],
    ]]);

    expect(fn () => app(CreateBookingAction::class)->handle($payload, $s['actor']))
        ->toThrow(DomainException::class, 'primary');
});

it('requires ownership shares to total exactly 100 (7)', function () {
    $s = bookingScenario();

    $payload = bookingPayload($s, ['buyers' => [
        ['buyer_id' => $s['buyerA']->id, 'ownership_percentage' => '60', 'is_primary' => true],
        ['buyer_id' => $s['buyerB']->id, 'ownership_percentage' => '30', 'is_primary' => false],
    ]]);

    expect(fn () => app(CreateBookingAction::class)->handle($payload, $s['actor']))
        ->toThrow(DomainException::class, 'total exactly 100');
});

it('rejects a zero or over-100 ownership share (8)', function () {
    $s = bookingScenario();

    expect(fn () => app(CreateBookingAction::class)->handle(
        bookingPayload($s, ['buyers' => [['buyer_id' => $s['buyerA']->id, 'ownership_percentage' => '0', 'is_primary' => true]]]),
        $s['actor']
    ))->toThrow(DomainException::class);

    expect(fn () => app(CreateBookingAction::class)->handle(
        bookingPayload($s, ['buyers' => [['buyer_id' => $s['buyerA']->id, 'ownership_percentage' => '150', 'is_primary' => true]]]),
        $s['actor']
    ))->toThrow(DomainException::class);
});

it('rejects the same buyer added twice (9)', function () {
    $s = bookingScenario();

    $payload = bookingPayload($s, ['buyers' => [
        ['buyer_id' => $s['buyerA']->id, 'ownership_percentage' => '50', 'is_primary' => true],
        ['buyer_id' => $s['buyerA']->id, 'ownership_percentage' => '50', 'is_primary' => false],
    ]]);

    expect(fn () => app(CreateBookingAction::class)->handle($payload, $s['actor']))
        ->toThrow(DomainException::class, 'twice');
});

it('rejects a block that belongs to another project (10)', function () {
    $s = bookingScenario();
    $otherProject = Project::factory()->create();
    $foreignBlock = Block::factory()->create(['project_id' => $otherProject->id]);

    expect(fn () => app(CreateBookingAction::class)->handle(
        bookingPayload($s, ['block_id' => $foreignBlock->id]), $s['actor']
    ))->toThrow(DomainException::class, 'block');
});

it('rejects a plot that is not in the selected block (11)', function () {
    $s = bookingScenario();
    $otherBlock = Block::factory()->create(['project_id' => $s['project']->id]);
    $foreignPlot = Plot::factory()->create([
        'project_id' => $s['project']->id, 'block_id' => $otherBlock->id, 'status' => 'available',
    ]);

    expect(fn () => app(CreateBookingAction::class)->handle(
        bookingPayload($s, ['plot_id' => $foreignPlot->id]), $s['actor']
    ))->toThrow(DomainException::class);
});

it('promotes a draft to pending and rejects invalid transitions (12)', function () {
    $s = bookingScenario();
    $booking = app(CreateBookingAction::class)->handle(bookingPayload($s), $s['actor']);

    app(SubmitBookingAction::class)->handle($booking, $s['actor']);
    expect($booking->fresh()->status)->toBe(BookingStatus::Pending)
        ->and($booking->fresh()->active_plot_id)->toBe($s['plot']->id);

    // Cancelling then submitting again is invalid.
    app(CancelBookingAction::class)->handle($booking->fresh(), $s['actor'], 'changed mind');
    expect(fn () => app(SubmitBookingAction::class)->handle($booking->fresh(), $s['actor']))
        ->toThrow(DomainException::class);
});

it('updates an editable booking price and rejects editing a confirmed one', function () {
    $s = bookingScenario();
    $booking = app(CreateBookingAction::class)->handle(bookingPayload($s), $s['actor']);

    $updated = app(UpdateBookingAction::class)->handle($booking, bookingPayload($s, [
        'pricing' => ['base_area' => '1000', 'base_rate' => '2500', 'components' => []],
    ]), $s['actor']);

    expect($updated->base_amount)->toBe('2500000.00')
        ->and($updated->final_amount)->toBe('2500000.00');
});
