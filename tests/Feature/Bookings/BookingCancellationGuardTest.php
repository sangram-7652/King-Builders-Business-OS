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
use App\Exceptions\DomainException;
use App\Models\Agreement;
use App\Support\Bookings\BookingCancellationGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(fn () => seedRbac());

/**
 * F-M6-1 (redesigned) — a CONFIRMED booking is ALWAYS cancellable by an
 * authorised operator. {@see BookingCancellationGuard}
 * only blocks the handful of cases the cancellation workflow genuinely cannot
 * resolve safely on its own: live money, a signed agreement, or the plot
 * already in a state cancellation cannot reverse. Everything else (Registry /
 * Possession / Transfer) is cascaded to Cancelled instead of blocking — see
 * {@see tests/Feature/Bookings/BookingCancellationCascadeTest.php}.
 */
it('cancels a confirmed booking with no downstream dependencies and frees the plot', function () {
    $s = confirmedBookingScenario();

    $cancelled = app(CancelBookingAction::class)->handle($s['booking']->fresh(), $s['actor'], 'buyer withdrew');

    expect($cancelled->status)->toBe(BookingStatus::Cancelled)
        ->and($cancelled->cancellation_reason)->toBe('buyer withdrew')
        ->and($cancelled->cancelled_by)->toBe($s['actor']->id)
        ->and($cancelled->cancelled_at)->not->toBeNull()
        ->and($cancelled->plot->fresh()->status)->toBe(PlotStatus::Available)
        // financial truth untouched
        ->and($cancelled->final_amount)->toBe($s['booking']->final_amount);
});

it('rejects cancellation of a confirmed booking with a successful payment and keeps the plot BOOKED', function () {
    $s = confirmedBookingScenario();
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

it('rejects cancellation while a signed agreement exists', function () {
    $s = confirmedBookingScenario();
    Agreement::factory()->signed()->create(['booking_id' => $s['booking']->id]);

    expect(fn () => app(CancelBookingAction::class)->handle($s['booking']->fresh(), $s['actor'], 'x'))
        ->toThrow(DomainException::class, 'signed agreement');

    expect($s['booking']->fresh()->status)->toBe(BookingStatus::Confirmed);
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

it('is idempotent — cancelling an already-cancelled booking again is a safe no-op (16)', function () {
    $s = confirmedBookingScenario();

    $first = app(CancelBookingAction::class)->handle($s['booking']->fresh(), $s['actor'], 'buyer withdrew');
    $firstCancelledAt = $first->cancelled_at;

    $second = app(CancelBookingAction::class)->handle($s['booking']->fresh(), $s['actor'], 'a different reason');

    expect($second->status)->toBe(BookingStatus::Cancelled)
        // the second call never re-runs the cancellation — nothing is overwritten
        ->and($second->cancellation_reason)->toBe('buyer withdrew')
        ->and($second->cancelled_at->equalTo($firstCancelledAt))->toBeTrue()
        ->and($s['booking']->plot->fresh()->status)->toBe(PlotStatus::Available);
});
