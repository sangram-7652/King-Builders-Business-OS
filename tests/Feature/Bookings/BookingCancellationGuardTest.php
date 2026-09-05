<?php

declare(strict_types=1);

use App\Actions\Bookings\CancelBookingAction;
use App\Actions\Bookings\CreateBookingAction;
use App\Actions\Bookings\SubmitBookingAction;
use App\Actions\Payments\RecordPaymentAction;
use App\Actions\Payments\VerifyPaymentAction;
use App\Enums\BookingStatus;
use App\Enums\CommissionCaseStatus;
use App\Enums\PaymentStatus;
use App\Enums\PlotStatus;
use App\Enums\TransferRequestStatus;
use App\Exceptions\DomainException;
use App\Models\Agreement;
use App\Models\CollectionCase;
use App\Models\PossessionCase;
use App\Models\RegistryCase;
use App\Models\TransferRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(fn () => seedRbac());

/**
 * F-M6-1 — a CONFIRMED booking must not be cancellable through the normal path
 * once downstream financial / operational records reference it.
 */
it('cancels a confirmed booking with no downstream dependencies and frees the plot', function () {
    $s = confirmedBookingScenario();

    $cancelled = app(CancelBookingAction::class)->handle($s['booking']->fresh(), $s['actor'], 'buyer withdrew');

    expect($cancelled->status)->toBe(BookingStatus::Cancelled)
        ->and($cancelled->cancellation_reason)->toBe('buyer withdrew')
        ->and($cancelled->plot->fresh()->status)->toBe(PlotStatus::Available)
        // financial truth untouched
        ->and($cancelled->final_amount)->toBe($s['booking']->final_amount);
});

it('rejects cancellation of a confirmed booking with a successful payment and keeps the plot BOOKED', function () {
    $s = confirmedBookingScenario();
    activePlanFor($s['booking'], $s['actor']);
    $payment = app(RecordPaymentAction::class)->handle([
        'booking_id' => $s['booking']->id, 'payment_mode_id' => cashMode()->id,
        'amount' => '100000', 'payment_date' => now()->toDateString(),
    ], $s['actor']);
    app(VerifyPaymentAction::class)->handle($payment, PaymentStatus::Success, $s['actor']);

    expect(fn () => app(CancelBookingAction::class)->handle($s['booking']->fresh(), $s['actor'], 'x'))
        ->toThrow(DomainException::class, 'pending/successful payment');

    expect($s['booking']->fresh()->status)->toBe(BookingStatus::Confirmed)
        ->and($s['booking']->plot->fresh()->status)->toBe(PlotStatus::Booked);
});

it('rejects cancellation while an active payment plan exists', function () {
    $s = confirmedBookingScenario();
    activePlanFor($s['booking'], $s['actor']); // no payments recorded

    expect(fn () => app(CancelBookingAction::class)->handle($s['booking']->fresh(), $s['actor'], 'x'))
        ->toThrow(DomainException::class, 'active payment plan');

    expect($s['booking']->fresh()->status)->toBe(BookingStatus::Confirmed)
        ->and($s['booking']->plot->fresh()->status)->toBe(PlotStatus::Booked);
});

it('rejects cancellation while an open collection case exists', function () {
    $s = confirmedBookingScenario();
    CollectionCase::factory()->forBooking($s['booking'])->create();

    expect(fn () => app(CancelBookingAction::class)->handle($s['booking']->fresh(), $s['actor'], 'x'))
        ->toThrow(DomainException::class, 'collection case');

    expect($s['booking']->fresh()->status)->toBe(BookingStatus::Confirmed)
        ->and($s['booking']->plot->fresh()->status)->toBe(PlotStatus::Booked);
});

it('rejects cancellation while a registry case exists', function () {
    $s = confirmedBookingScenario();
    RegistryCase::factory()->forBooking($s['booking'])->create();

    expect(fn () => app(CancelBookingAction::class)->handle($s['booking']->fresh(), $s['actor'], 'x'))
        ->toThrow(DomainException::class, 'registry case');

    expect($s['booking']->fresh()->status)->toBe(BookingStatus::Confirmed);
});

it('rejects cancellation while a possession case exists', function () {
    $s = confirmedBookingScenario();
    PossessionCase::factory()->forBooking($s['booking'])->create();

    expect(fn () => app(CancelBookingAction::class)->handle($s['booking']->fresh(), $s['actor'], 'x'))
        ->toThrow(DomainException::class, 'possession case');

    expect($s['booking']->fresh()->status)->toBe(BookingStatus::Confirmed);
});

it('rejects cancellation while an ownership transfer request is in progress', function () {
    $s = confirmedBookingScenario();
    TransferRequest::factory()->forBooking($s['booking'])->status(TransferRequestStatus::UnderReview)->create();

    expect(fn () => app(CancelBookingAction::class)->handle($s['booking']->fresh(), $s['actor'], 'x'))
        ->toThrow(DomainException::class, 'transfer request');

    expect($s['booking']->fresh()->status)->toBe(BookingStatus::Confirmed);
});

it('rejects cancellation while a signed agreement exists', function () {
    $s = confirmedBookingScenario();
    Agreement::factory()->signed()->create(['booking_id' => $s['booking']->id]);

    expect(fn () => app(CancelBookingAction::class)->handle($s['booking']->fresh(), $s['actor'], 'x'))
        ->toThrow(DomainException::class, 'signed agreement');
});

it('allows cancellation once a rejected transfer request is the only downstream record', function () {
    $s = confirmedBookingScenario();
    TransferRequest::factory()->forBooking($s['booking'])->status(TransferRequestStatus::Rejected)->create();

    $cancelled = app(CancelBookingAction::class)->handle($s['booking']->fresh(), $s['actor'], 'ok now');

    expect($cancelled->status)->toBe(BookingStatus::Cancelled)
        ->and($s['booking']->plot->fresh()->status)->toBe(PlotStatus::Available);
});

it('still cascades to commission cases (a commission case alone is not a blocker)', function () {
    ['actor' => $actor, 'booking' => $booking, 'case' => $case] = pendingCase();

    app(CancelBookingAction::class)->handle($booking->fresh(), $actor, 'buyer withdrew');

    expect($booking->fresh()->status)->toBe(BookingStatus::Cancelled)
        ->and($case->fresh()->status)->toBe(CommissionCaseStatus::Cancelled);
});

it('leaves DRAFT / PENDING cancellation unaffected', function () {
    $s = bookingScenario();
    $booking = app(CreateBookingAction::class)->handle(bookingPayload($s), $s['actor']);
    app(SubmitBookingAction::class)->handle($booking, $s['actor']);

    app(CancelBookingAction::class)->handle($booking->fresh(), $s['actor'], null);

    expect($booking->fresh()->status)->toBe(BookingStatus::Cancelled)
        ->and($booking->fresh()->active_plot_id)->toBeNull();
});
