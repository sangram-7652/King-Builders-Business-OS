<?php

declare(strict_types=1);

use App\Actions\Bookings\CancelBookingAction;
use App\Actions\Bookings\CreateBookingAction;
use App\Actions\Bookings\SubmitBookingAction;
use App\Actions\Payments\RecordPaymentAction;
use App\Actions\Payments\ReversePaymentAction;
use App\Actions\Payments\VerifyPaymentAction;
use App\Actions\Plots\ChangePlotStatus;
use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use App\Enums\PlotStatus;
use App\Enums\PossessionCaseStatus;
use App\Enums\RegistryCaseStatus;
use App\Enums\TransferRequestStatus;
use App\Exceptions\DomainException;
use App\Models\Booking;
use App\Models\Buyer;
use App\Models\Document;
use App\Models\Payment;
use App\Models\PossessionCase;
use App\Models\RegistryCase;
use App\Models\TransferRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    seedRbac();
    seedDocumentMasters();
});

/*
| ---------------------------------------------------------------------------
| The exact BK-000001 scenario:
|   Registry Case = completed, Possession Case = eligibility_pending,
|   Transfer Request = under_review, Payment = reversed.
| ---------------------------------------------------------------------------
*/

it('BK-000001 scenario: cancels the booking, releases the plot, cascades to active downstream records, and preserves the rest', function () {
    $s = confirmedBookingScenario();
    $booking = $s['booking'];

    $payment = app(RecordPaymentAction::class)->handle([
        'booking_id' => $booking->id, 'payment_mode_id' => cashMode()->id,
        'amount' => '1000000', 'payment_date' => now()->toDateString(),
    ], $s['actor']);
    app(VerifyPaymentAction::class)->handle($payment, PaymentStatus::Success, $s['actor']);
    app(ReversePaymentAction::class)->handle($payment->fresh(), 'test reversal', $s['actor'], systemInitiated: true);

    $registryCase = RegistryCase::factory()->forBooking($booking)
        ->status(RegistryCaseStatus::Completed)
        ->create(['completed_at' => now()]);

    $possessionCase = PossessionCase::factory()->forBooking($booking)
        ->status(PossessionCaseStatus::EligibilityPending)
        ->create();

    $transfer = TransferRequest::factory()->forBooking($booking)
        ->status(TransferRequestStatus::UnderReview)
        ->create();

    $document = Document::factory()->verified()->forDocumentable($booking)
        ->state(['document_type_id' => docType('BOOKING_FORM')->id])->create();

    expect($payment->fresh()->status)->toBe(PaymentStatus::Reversed);

    $cancelled = app(CancelBookingAction::class)->handle($booking->fresh(), $s['actor'], 'buyer withdrew');

    // Booking → Cancelled, preserved (not deleted).
    expect($cancelled->status)->toBe(BookingStatus::Cancelled)
        ->and(Booking::find($booking->id))->not->toBeNull()
        ->and($cancelled->booking_number)->toBe($booking->booking_number)
        ->and($cancelled->final_amount)->toBe($booking->final_amount);

    // Plot → Available.
    expect($booking->plot->fresh()->status)->toBe(PlotStatus::Available);

    // Registry Case → preserved as Completed, untouched.
    expect($registryCase->fresh()->status)->toBe(RegistryCaseStatus::Completed)
        ->and($registryCase->fresh()->completed_at)->not->toBeNull();

    // Possession Case → Cancelled (was active).
    expect($possessionCase->fresh()->status)->toBe(PossessionCaseStatus::Cancelled);

    // Transfer Request → Cancelled (was active).
    expect($transfer->fresh()->status)->toBe(TransferRequestStatus::Cancelled);

    // Payment → remains Reversed, not deleted, not re-mutated.
    expect($payment->fresh()->status)->toBe(PaymentStatus::Reversed);

    // Documents → preserved.
    expect(Document::find($document->id))->not->toBeNull();

    // Buyer → intact, still linked historically.
    expect($booking->bookingBuyers()->where('buyer_id', $s['buyer']->id)->exists())->toBeTrue();

    // No records deleted anywhere.
    expect(RegistryCase::count())->toBe(1)
        ->and(PossessionCase::count())->toBe(1)
        ->and(TransferRequest::count())->toBe(1)
        ->and(Payment::count())->toBe(1);
});

/*
| ---------------------------------------------------------------------------
| Active downstream records are cascaded (individually).
| ---------------------------------------------------------------------------
*/

it('cascades cancellation to an active (non-terminal) Registry Case', function () {
    $s = confirmedBookingScenario();
    $registryCase = RegistryCase::factory()->forBooking($s['booking'])->status(RegistryCaseStatus::Ready)->create();

    $cancelled = app(CancelBookingAction::class)->handle($s['booking']->fresh(), $s['actor'], 'x');

    expect($cancelled->status)->toBe(BookingStatus::Cancelled)
        ->and($registryCase->fresh()->status)->toBe(RegistryCaseStatus::Cancelled)
        ->and($s['booking']->plot->fresh()->status)->toBe(PlotStatus::Available);
});

it('cascades cancellation to an active (non-terminal) Possession Case', function () {
    $s = confirmedBookingScenario();
    $possessionCase = PossessionCase::factory()->forBooking($s['booking'])->status(PossessionCaseStatus::Scheduled)->create();

    $cancelled = app(CancelBookingAction::class)->handle($s['booking']->fresh(), $s['actor'], 'x');

    expect($cancelled->status)->toBe(BookingStatus::Cancelled)
        ->and($possessionCase->fresh()->status)->toBe(PossessionCaseStatus::Cancelled)
        ->and($s['booking']->plot->fresh()->status)->toBe(PlotStatus::Available);
});

it('cascades cancellation to an active (non-terminal) Transfer Request', function () {
    $s = confirmedBookingScenario();
    $transfer = TransferRequest::factory()->forBooking($s['booking'])->status(TransferRequestStatus::Approved)->create();

    $cancelled = app(CancelBookingAction::class)->handle($s['booking']->fresh(), $s['actor'], 'x');

    expect($cancelled->status)->toBe(BookingStatus::Cancelled)
        ->and($transfer->fresh()->status)->toBe(TransferRequestStatus::Cancelled)
        ->and($s['booking']->plot->fresh()->status)->toBe(PlotStatus::Available);
});

/*
| ---------------------------------------------------------------------------
| Terminal downstream records are preserved, never force-transitioned.
| ---------------------------------------------------------------------------
*/

it('preserves a COMPLETED Registry Case and still cancels the booking', function () {
    $s = confirmedBookingScenario();
    $registryCase = RegistryCase::factory()->forBooking($s['booking'])
        ->status(RegistryCaseStatus::Completed)->create(['completed_at' => now()]);

    $cancelled = app(CancelBookingAction::class)->handle($s['booking']->fresh(), $s['actor'], 'x');

    expect($cancelled->status)->toBe(BookingStatus::Cancelled)
        ->and($registryCase->fresh()->status)->toBe(RegistryCaseStatus::Completed);
});

it('preserves an already CANCELLED / REJECTED Transfer Request untouched', function () {
    $s = confirmedBookingScenario();
    $rejected = TransferRequest::factory()->forBooking($s['booking'])
        ->status(TransferRequestStatus::Rejected)
        ->create(['rejected_at' => now(), 'rejection_reason' => 'not eligible']);

    $cancelled = app(CancelBookingAction::class)->handle($s['booking']->fresh(), $s['actor'], 'x');

    expect($cancelled->status)->toBe(BookingStatus::Cancelled)
        ->and($rejected->fresh()->status)->toBe(TransferRequestStatus::Rejected)
        ->and($rejected->fresh()->rejection_reason)->toBe('not eligible')
        ->and($s['booking']->plot->fresh()->status)->toBe(PlotStatus::Available);
});

it('preserves a COMPLETED Transfer Request untouched, never forcing it back to Cancelled', function () {
    $s = confirmedBookingScenario();
    $completed = TransferRequest::factory()->forBooking($s['booking'])
        ->status(TransferRequestStatus::Completed)->create();

    $cancelled = app(CancelBookingAction::class)->handle($s['booking']->fresh(), $s['actor'], 'x');

    expect($cancelled->status)->toBe(BookingStatus::Cancelled)
        ->and($completed->fresh()->status)->toBe(TransferRequestStatus::Completed)
        ->and($completed->fresh()->cancelled_at)->toBeNull();
});

/*
| ---------------------------------------------------------------------------
| The one genuine, unresolvable conflict: possession already physically
| handed over (plot already POSSESSION_COMPLETED). This must BLOCK, and the
| block must be atomic — nothing else gets touched either.
| ---------------------------------------------------------------------------
*/

it('refuses to cancel once possession has completed (plot is POSSESSION_COMPLETED), and touches nothing (13, 17)', function () {
    $s = confirmedBookingScenario();
    $booking = $s['booking'];

    $possessionCase = PossessionCase::factory()->forBooking($booking)
        ->status(PossessionCaseStatus::Completed)->create();
    app(ChangePlotStatus::class)->handle($booking->plot, PlotStatus::PossessionCompleted);

    // A second, still-active downstream record present at the same time —
    // proves the whole transaction is atomic: if it were touched before the
    // plot-state block fired, this assertion below would fail.
    $registryCase = RegistryCase::factory()->forBooking($booking)->status(RegistryCaseStatus::Ready)->create();

    expect(fn () => app(CancelBookingAction::class)->handle($booking->fresh(), $s['actor'], 'x'))
        ->toThrow(DomainException::class, 'Possession completed');

    expect($booking->fresh()->status)->toBe(BookingStatus::Confirmed)
        ->and($booking->plot->fresh()->status)->toBe(PlotStatus::PossessionCompleted)
        ->and($possessionCase->fresh()->status)->toBe(PossessionCaseStatus::Completed)
        // untouched — the guard blocked before any cascade ran
        ->and($registryCase->fresh()->status)->toBe(RegistryCaseStatus::Ready);
});

it('rejects cancellation while a live payment blocks it, without touching any active downstream record (atomicity)', function () {
    $s = confirmedBookingScenario();
    $booking = $s['booking'];

    $payment = app(RecordPaymentAction::class)->handle([
        'booking_id' => $booking->id, 'payment_mode_id' => cashMode()->id,
        'amount' => '50000', 'payment_date' => now()->toDateString(),
    ], $s['actor']);
    app(VerifyPaymentAction::class)->handle($payment, PaymentStatus::Success, $s['actor']);

    $registryCase = RegistryCase::factory()->forBooking($booking)->status(RegistryCaseStatus::Ready)->create();
    $possessionCase = PossessionCase::factory()->forBooking($booking)->status(PossessionCaseStatus::NotStarted)->create();

    expect(fn () => app(CancelBookingAction::class)->handle($booking->fresh(), $s['actor'], 'x'))
        ->toThrow(DomainException::class);

    expect($booking->fresh()->status)->toBe(BookingStatus::Confirmed)
        ->and($booking->plot->fresh()->status)->toBe(PlotStatus::Booked)
        ->and($registryCase->fresh()->status)->toBe(RegistryCaseStatus::Ready)
        ->and($possessionCase->fresh()->status)->toBe(PossessionCaseStatus::NotStarted);
});

/*
| ---------------------------------------------------------------------------
| Re-booking: the released plot must be available for a NEW booking, and the
| cancelled booking stays linked to it historically.
| ---------------------------------------------------------------------------
*/

it('lets a new booking claim the plot after cancellation, while the old booking stays historical', function () {
    $s = confirmedBookingScenario();
    $oldBooking = $s['booking'];
    $plot = $oldBooking->plot;

    app(CancelBookingAction::class)->handle($oldBooking->fresh(), $s['actor'], 'buyer withdrew');

    expect($plot->fresh()->status)->toBe(PlotStatus::Available);

    $newBuyer = Buyer::factory()->create(['status' => 'active']);
    $s2 = [
        'actor' => $s['actor'],
        'project' => $oldBooking->project,
        'block' => $oldBooking->block,
        'plot' => $plot,
        'buyerA' => $newBuyer,
    ];
    $newBooking = app(CreateBookingAction::class)->handle(bookingPayload($s2), $s['actor']);
    app(SubmitBookingAction::class)->handle($newBooking, $s['actor']);

    expect($newBooking->fresh()->status)->toBe(BookingStatus::Pending)
        ->and($newBooking->fresh()->plot_id)->toBe($plot->id)
        // the old, cancelled booking is untouched and still historically on the same plot
        ->and($oldBooking->fresh()->status)->toBe(BookingStatus::Cancelled)
        ->and($oldBooking->fresh()->plot_id)->toBe($plot->id)
        // never two live bookings on the same plot
        ->and(Booking::where('plot_id', $plot->id)->whereIn('status', ['pending', 'confirmed'])->count())->toBe(1);
});

/*
| ---------------------------------------------------------------------------
| Concurrency safety — both the booking and the plot are locked FOR UPDATE.
| ---------------------------------------------------------------------------
*/

it('locks both the booking and the plot row FOR UPDATE during cancellation (14)', function () {
    $s = confirmedBookingScenario();

    $sql = [];
    DB::listen(fn ($q) => $sql[] = strtolower($q->sql));

    app(CancelBookingAction::class)->handle($s['booking']->fresh(), $s['actor'], 'x');

    $forUpdateCount = collect($sql)->filter(fn (string $q) => str_contains($q, 'for update'))->count();
    expect($forUpdateCount)->toBeGreaterThanOrEqual(2, 'CancelBookingAction must lock both the booking and the plot row FOR UPDATE');
})->skip(fn () => DB::connection()->getDriverName() === 'sqlite', 'SQLite has no row-level FOR UPDATE');

/*
| ---------------------------------------------------------------------------
| Authorization — unchanged, still enforced.
| ---------------------------------------------------------------------------
*/

it('a user without bookings.cancel is not authorised to cancel a booking (15)', function () {
    $s = confirmedBookingScenario();
    $clerk = bookingClerk();
    $manager = bookingManager();

    expect($clerk->can('cancel', $s['booking']->fresh()))->toBeFalse()
        ->and($manager->can('cancel', $s['booking']->fresh()))->toBeTrue();
});
