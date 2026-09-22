<?php

declare(strict_types=1);

use App\Actions\Bookings\CancelBookingAction;
use App\Actions\Bookings\CreateBookingAction;
use App\Actions\Possession\MarkPossessionDoneAction;
use App\Actions\Registry\MarkRegistryDoneAction;
use App\Enums\BookingStatus;
use App\Enums\PlotStatus;
use App\Enums\PossessionStatus;
use App\Enums\RegistryStatus;
use App\Exceptions\DomainException;
use App\Livewire\Bookings\BookingPossession;
use App\Models\Booking;
use App\Models\Buyer;
use App\Models\Document;
use App\Models\Payment;
use App\Models\PossessionActivity;
use App\Models\PossessionCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    seedRbac();
    seedDocumentMasters();
    Storage::fake('documents');
});

/*
| ---------------------------------------------------------------------------
| 1-4. Default state, no eligibility gate of any kind.
| ---------------------------------------------------------------------------
*/

it('a new confirmed booking starts Possession = Pending (1)', function () {
    $s = confirmedBookingScenario();

    expect($s['booking']->fresh()->possession_status)->toBe(PossessionStatus::Pending);
});

it('Possession Pending does not require Registry Done (2)', function () {
    $s = confirmedBookingScenario();

    expect($s['booking']->fresh()->registry_status)->toBe(RegistryStatus::Pending)
        ->and($s['booking']->fresh()->possession_status)->toBe(PossessionStatus::Pending);
});

it('Possession Pending does not require payment collection (3)', function () {
    $s = confirmedBookingScenario('5000000'); // zero collected

    expect($s['booking']->fresh()->possession_status)->toBe(PossessionStatus::Pending);
});

it('Possession Pending does not require documents (4)', function () {
    $s = confirmedBookingScenario();
    // No buyer/booking documents created at all.

    expect($s['booking']->fresh()->possession_status)->toBe(PossessionStatus::Pending);
});

/*
| ---------------------------------------------------------------------------
| 5-7. Authorized user can change to Done, without any eligibility gate.
| ---------------------------------------------------------------------------
*/

it('an authorised user can change Possession to Done (5)', function () {
    $s = confirmedBookingScenario();

    $updated = app(MarkPossessionDoneAction::class)->handle($s['booking']->fresh(), possessionOfficer());

    expect($updated->possession_status)->toBe(PossessionStatus::Done);
});

it('Possession Done does not require 100% collection (6)', function () {
    $s = confirmedBookingScenario('5000000'); // zero collected — would fail the OLD 100% gate
    config(['possession.eligibility.required_paid_percent' => 100]);

    $updated = app(MarkPossessionDoneAction::class)->handle($s['booking']->fresh(), possessionOfficer());

    expect($updated->possession_status)->toBe(PossessionStatus::Done);
});

it('Possession Done does not require document verification (7)', function () {
    $s = confirmedBookingScenario();
    // Explicitly leave buyer + booking documents completely unverified/absent.

    $updated = app(MarkPossessionDoneAction::class)->handle($s['booking']->fresh(), possessionOfficer());

    expect($updated->possession_status)->toBe(PossessionStatus::Done);
});

/*
| ---------------------------------------------------------------------------
| 8-9. Plot interaction — Possession never touches Plot; only Registry does.
| ---------------------------------------------------------------------------
*/

it('Possession Done does not change Plot status (8)', function () {
    $s = confirmedBookingScenario();

    app(MarkPossessionDoneAction::class)->handle($s['booking']->fresh(), possessionOfficer());

    expect($s['booking']->plot->fresh()->status)->toBe(PlotStatus::Booked);
});

it('Registry Done still controls Plot = Sold, independent of Possession (9)', function () {
    $s = confirmedBookingScenario();

    app(MarkPossessionDoneAction::class)->handle($s['booking']->fresh(), possessionOfficer());
    app(MarkRegistryDoneAction::class)->handle($s['booking']->fresh(), registryOfficer());

    expect($s['booking']->plot->fresh()->status)->toBe(PlotStatus::Sold)
        ->and($s['booking']->fresh()->possession_status)->toBe(PossessionStatus::Done)
        ->and($s['booking']->fresh()->registry_status)->toBe(RegistryStatus::Done);
});

it('if Registry already made the plot Sold, marking Possession Done leaves it Sold — never overwritten', function () {
    $s = confirmedBookingScenario();

    app(MarkRegistryDoneAction::class)->handle($s['booking']->fresh(), registryOfficer());
    expect($s['booking']->plot->fresh()->status)->toBe(PlotStatus::Sold);

    app(MarkPossessionDoneAction::class)->handle($s['booking']->fresh(), possessionOfficer());

    expect($s['booking']->plot->fresh()->status)->toBe(PlotStatus::Sold);
});

/*
| ---------------------------------------------------------------------------
| The exact BK-000002 scenario.
| ---------------------------------------------------------------------------
*/

it('BK-000002 scenario: Possession Done first, then Registry Done — Plot only changes when Registry does', function () {
    $s = confirmedBookingScenario();
    $booking = $s['booking'];

    expect($booking->fresh()->registry_status)->toBe(RegistryStatus::Pending)
        ->and($booking->fresh()->possession_status)->toBe(PossessionStatus::Pending)
        ->and($booking->plot->fresh()->status)->toBe(PlotStatus::Booked);

    app(MarkPossessionDoneAction::class)->handle($booking->fresh(), possessionOfficer());

    expect($booking->fresh()->registry_status)->toBe(RegistryStatus::Pending)
        ->and($booking->fresh()->possession_status)->toBe(PossessionStatus::Done)
        ->and($booking->plot->fresh()->status)->toBe(PlotStatus::Booked);

    app(MarkRegistryDoneAction::class)->handle($booking->fresh(), registryOfficer());

    expect($booking->fresh()->registry_status)->toBe(RegistryStatus::Done)
        ->and($booking->fresh()->possession_status)->toBe(PossessionStatus::Done)
        ->and($booking->plot->fresh()->status)->toBe(PlotStatus::Sold);
});

/*
| ---------------------------------------------------------------------------
| 10. Status preserved (idempotency / persistence).
| ---------------------------------------------------------------------------
*/

it('Possession status is preserved — marking Done twice is a safe no-op (10)', function () {
    $s = confirmedBookingScenario();

    app(MarkPossessionDoneAction::class)->handle($s['booking']->fresh(), possessionOfficer());
    $again = app(MarkPossessionDoneAction::class)->handle($s['booking']->fresh(), possessionOfficer());

    expect($again->possession_status)->toBe(PossessionStatus::Done)
        ->and($s['booking']->fresh()->possession_status)->toBe(PossessionStatus::Done);
});

it('rejects changing Possession status for a booking that is not confirmed', function () {
    $s = bookingScenario();
    $booking = app(CreateBookingAction::class)->handle(bookingPayload($s), $s['actor']);

    expect(fn () => app(MarkPossessionDoneAction::class)->handle($booking, possessionOfficer()))
        ->toThrow(DomainException::class, 'confirmed');
});

/*
| ---------------------------------------------------------------------------
| 11. Authorization.
| ---------------------------------------------------------------------------
*/

it('an unauthorised user cannot change the Possession status (11)', function () {
    $s = confirmedBookingScenario();
    $noPermission = makeUser(permissions: ['possession.view', 'bookings.view']);

    expect(fn () => app(MarkPossessionDoneAction::class)->handle($s['booking']->fresh(), $noPermission))
        ->toThrow(DomainException::class, 'not authorised');

    expect($s['booking']->fresh()->possession_status)->toBe(PossessionStatus::Pending);
});

it('the Mark Done button is hidden from a user without possession.complete', function () {
    $s = confirmedBookingScenario();
    $noComplete = makeUser(permissions: ['possession.view', 'bookings.view']);

    Livewire::actingAs($noComplete)
        ->test(BookingPossession::class, ['booking' => $s['booking']])
        ->assertDontSee('Mark Done');
});

it('the Possession screen has no eligibility card, percentage, or "Open possession case" button', function () {
    $s = confirmedBookingScenario();

    $html = Livewire::actingAs(possessionOfficer())
        ->test(BookingPossession::class, ['booking' => $s['booking']])
        ->assertOk()
        ->assertSee('Pending')
        ->assertDontSee('Not eligible')
        ->assertDontSee('Open possession case')
        ->assertDontSee('Registry completed')
        ->assertDontSee('collected')
        ->html();

    expect($html)->not->toContain('Not eligible')
        ->not->toContain('Open possession case');
});

/*
| ---------------------------------------------------------------------------
| 12. Historical data preserved.
| ---------------------------------------------------------------------------
*/

it('existing historical Possession Case records are preserved untouched (12)', function () {
    $s = confirmedBookingScenario();
    $case = PossessionCase::factory()->forBooking($s['booking'])->create();

    app(MarkPossessionDoneAction::class)->handle($s['booking']->fresh(), possessionOfficer());

    expect(PossessionCase::find($case->id))->not->toBeNull()
        ->and(PossessionCase::count())->toBe(1);
});

it('marking Possession Done never creates a Possession Case', function () {
    $s = confirmedBookingScenario();

    app(MarkPossessionDoneAction::class)->handle($s['booking']->fresh(), possessionOfficer());

    expect(PossessionCase::count())->toBe(0);
});

/*
| ---------------------------------------------------------------------------
| Atomicity / locking.
| ---------------------------------------------------------------------------
*/

it('locks the booking row FOR UPDATE while changing the Possession status', function () {
    $s = confirmedBookingScenario();

    $sql = [];
    DB::listen(fn ($q) => $sql[] = strtolower($q->sql));

    app(MarkPossessionDoneAction::class)->handle($s['booking']->fresh(), possessionOfficer());

    expect(collect($sql)->contains(fn (string $q) => str_contains($q, 'for update')))->toBeTrue();
})->skip(fn () => DB::connection()->getDriverName() === 'sqlite', 'SQLite has no row-level FOR UPDATE');

/*
| ---------------------------------------------------------------------------
| Audit trail.
| ---------------------------------------------------------------------------
*/

it('records who/when/previous/new status when Possession is marked Done', function () {
    $s = confirmedBookingScenario();
    $officer = possessionOfficer();

    app(MarkPossessionDoneAction::class)->handle($s['booking']->fresh(), $officer);

    $event = PossessionActivity::where('booking_id', $s['booking']->id)->latest('id')->first();
    expect($event)->not->toBeNull()
        ->and($event->causer_id)->toBe($officer->id)
        ->and($event->created_at)->not->toBeNull()
        ->and($event->properties['from'])->toBe('pending')
        ->and($event->properties['to'])->toBe('done');
});

/*
| ---------------------------------------------------------------------------
| 13. Cancellation compatibility.
| ---------------------------------------------------------------------------
*/

it('booking cancellation remains compatible with Possession Pending (13)', function () {
    $s = confirmedBookingScenario();

    $cancelled = app(CancelBookingAction::class)->handle($s['booking']->fresh(), $s['actor'], 'buyer withdrew');

    expect($cancelled->status)->toBe(BookingStatus::Cancelled)
        ->and($s['booking']->plot->fresh()->status)->toBe(PlotStatus::Available);
});

it('booking cancellation remains compatible with Possession Done (plot untouched by Possession, still Booked)', function () {
    $s = confirmedBookingScenario();
    app(MarkPossessionDoneAction::class)->handle($s['booking']->fresh(), possessionOfficer());

    $cancelled = app(CancelBookingAction::class)->handle($s['booking']->fresh(), $s['actor'], 'buyer withdrew');

    expect($cancelled->status)->toBe(BookingStatus::Cancelled)
        ->and($s['booking']->fresh()->possession_status)->toBe(PossessionStatus::Done)
        ->and($s['booking']->plot->fresh()->status)->toBe(PlotStatus::Available);
});

it('an old historical Possession Case never blocks cancellation when it is still active', function () {
    $s = confirmedBookingScenario();
    $case = PossessionCase::factory()->forBooking($s['booking'])->create();

    $cancelled = app(CancelBookingAction::class)->handle($s['booking']->fresh(), $s['actor'], 'buyer withdrew');

    expect($cancelled->status)->toBe(BookingStatus::Cancelled)
        ->and(PossessionCase::find($case->id))->not->toBeNull();
});

/*
| ---------------------------------------------------------------------------
| Nothing is deleted.
| ---------------------------------------------------------------------------
*/

it('Possession Done does not delete the booking, buyer, payments or documents', function () {
    $s = confirmedBookingScenario();
    $booking = $s['booking'];

    $payment = payIn($booking, $s['actor'], '100000', now()->toDateString());
    $document = Document::factory()->verified()->forDocumentable($booking)
        ->state(['document_type_id' => docType('BOOKING_FORM')->id])->create();

    app(MarkPossessionDoneAction::class)->handle($booking->fresh(), possessionOfficer());

    expect(Booking::find($booking->id))->not->toBeNull()
        ->and(Buyer::find($s['buyer']->id))->not->toBeNull()
        ->and(Payment::find($payment->id))->not->toBeNull()
        ->and(Document::find($document->id))->not->toBeNull();
});
