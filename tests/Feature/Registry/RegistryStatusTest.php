<?php

declare(strict_types=1);

use App\Actions\Bookings\CancelBookingAction;
use App\Actions\Bookings\CreateBookingAction;
use App\Actions\Registry\ChangeRegistryStatusAction;
use App\Enums\BookingStatus;
use App\Enums\PlotStatus;
use App\Enums\RegistryStatus;
use App\Exceptions\DomainException;
use App\Livewire\Bookings\BookingRegistry;
use App\Models\Booking;
use App\Models\Buyer;
use App\Models\Document;
use App\Models\DocumentActivity;
use App\Models\Payment;
use App\Models\RegistryCase;
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
| 1. Default state
| ---------------------------------------------------------------------------
*/

it('a new confirmed booking starts Registry = Pending (1)', function () {
    $s = confirmedBookingScenario();

    expect($s['booking']->fresh()->registry_status)->toBe(RegistryStatus::Pending);
});

it('Registry Pending keeps the plot Booked (2)', function () {
    $s = confirmedBookingScenario();

    expect($s['booking']->fresh()->registry_status)->toBe(RegistryStatus::Pending)
        ->and($s['booking']->plot->fresh()->status)->toBe(PlotStatus::Booked);
});

/*
| ---------------------------------------------------------------------------
| The exact BK-000002 scenario.
| ---------------------------------------------------------------------------
*/

it('BK-000002 scenario: Registry Pending/Booked, then marked Done → Registry Done/Plot Sold, no case, no gate', function () {
    $s = confirmedBookingScenario();
    $booking = $s['booking'];

    expect($booking->fresh()->registry_status)->toBe(RegistryStatus::Pending)
        ->and($booking->plot->fresh()->status)->toBe(PlotStatus::Booked);

    $updated = app(ChangeRegistryStatusAction::class)->handle($booking->fresh(), RegistryStatus::Done, registryOfficer());

    expect($updated->registry_status)->toBe(RegistryStatus::Done)
        ->and($updated->plot->status)->toBe(PlotStatus::Sold)
        ->and(RegistryCase::count())->toBe(0);
});

/*
| ---------------------------------------------------------------------------
| 3-4. Changing to Done, without any eligibility gate.
| ---------------------------------------------------------------------------
*/

it('changes Registry to Done without any collection percentage (3)', function () {
    $s = confirmedBookingScenario('5000000'); // large amount, ZERO collected
    $officer = registryOfficer();

    $updated = app(ChangeRegistryStatusAction::class)->handle($s['booking']->fresh(), RegistryStatus::Done, $officer);

    expect($updated->registry_status)->toBe(RegistryStatus::Done);
});

it('Registry Done changes the plot to Sold (4, 18)', function () {
    $s = confirmedBookingScenario();

    app(ChangeRegistryStatusAction::class)->handle($s['booking']->fresh(), RegistryStatus::Done, registryOfficer());

    expect($s['booking']->plot->fresh()->status)->toBe(PlotStatus::Sold);
});

/*
| ---------------------------------------------------------------------------
| 5-8. Nothing is deleted.
| ---------------------------------------------------------------------------
*/

it('Registry Done does not delete the booking, buyer, payments or documents (5, 6, 7, 8)', function () {
    $s = confirmedBookingScenario();
    $booking = $s['booking'];

    $payment = payIn($booking, $s['actor'], '100000', now()->toDateString());
    $document = Document::factory()->verified()->forDocumentable($booking)
        ->state(['document_type_id' => docType('BOOKING_FORM')->id])->create();

    app(ChangeRegistryStatusAction::class)->handle($booking->fresh(), RegistryStatus::Done, registryOfficer());

    expect(Booking::find($booking->id))->not->toBeNull()
        ->and(Buyer::find($s['buyer']->id))->not->toBeNull()
        ->and(Payment::find($payment->id))->not->toBeNull()
        ->and(Document::find($document->id))->not->toBeNull();
});

/*
| ---------------------------------------------------------------------------
| 9-11, 14, 15. No eligibility gate of any kind.
| ---------------------------------------------------------------------------
*/

it('Registry Done does not require buyer or booking document verification (9, 10)', function () {
    $s = confirmedBookingScenario();
    // Explicitly leave buyer + booking documents completely unverified/absent.

    $updated = app(ChangeRegistryStatusAction::class)->handle($s['booking']->fresh(), RegistryStatus::Done, registryOfficer());

    expect($updated->registry_status)->toBe(RegistryStatus::Done);
});

it('Registry Done does not require a signed Agreement (11)', function () {
    $s = confirmedBookingScenario();
    // No Agreement row created at all for this booking.

    $updated = app(ChangeRegistryStatusAction::class)->handle($s['booking']->fresh(), RegistryStatus::Done, registryOfficer());

    expect($updated->registry_status)->toBe(RegistryStatus::Done);
});

it('RegistryEligibilityService is no longer consulted by the Registry workflow (14, 15)', function () {
    // Configure the OLD eligibility engine to require everything and fail outright.
    config(['registry.eligibility.required_paid_percent' => 100]);

    $s = confirmedBookingScenario('5000000'); // zero collected — would fail the OLD gate

    $updated = app(ChangeRegistryStatusAction::class)->handle($s['booking']->fresh(), RegistryStatus::Done, registryOfficer());

    expect($updated->registry_status)->toBe(RegistryStatus::Done)
        ->and($updated->plot->status)->toBe(PlotStatus::Sold);
});

/*
| ---------------------------------------------------------------------------
| 12-13. No Registry Case, no "Open registry case" UI.
| ---------------------------------------------------------------------------
*/

it('marking Registry Done never creates a Registry Case (12)', function () {
    $s = confirmedBookingScenario();

    app(ChangeRegistryStatusAction::class)->handle($s['booking']->fresh(), RegistryStatus::Done, registryOfficer());

    expect(RegistryCase::count())->toBe(0);
});

it('the Registry screen has no "Open registry case" button, eligibility card or case UI (13)', function () {
    $s = confirmedBookingScenario();

    $html = Livewire::actingAs(registryOfficer())
        ->test(BookingRegistry::class, ['booking' => $s['booking']])
        ->assertOk()
        ->assertSee('Pending')
        ->assertDontSee('Open registry case')
        ->assertDontSee('Registry eligibility')
        ->assertDontSee('Registry case')
        ->assertDontSee('At least')
        ->html();

    expect($html)->not->toContain('Open registry case')
        ->not->toContain('Registry eligibility');
});

/*
| ---------------------------------------------------------------------------
| 16. Authorization.
| ---------------------------------------------------------------------------
*/

it('an unauthorised user cannot change the Registry status (16)', function () {
    $s = confirmedBookingScenario();
    $noPermission = makeUser(permissions: ['registry.view', 'bookings.view']);

    expect(fn () => app(ChangeRegistryStatusAction::class)->handle($s['booking']->fresh(), RegistryStatus::Done, $noPermission))
        ->toThrow(DomainException::class, 'not authorised');

    expect($s['booking']->fresh()->registry_status)->toBe(RegistryStatus::Pending)
        ->and($s['booking']->plot->fresh()->status)->toBe(PlotStatus::Booked);
});

it('the Mark Done button is hidden from a user without registry.complete', function () {
    $s = confirmedBookingScenario();
    $noComplete = makeUser(permissions: ['registry.view', 'bookings.view']);

    Livewire::actingAs($noComplete)
        ->test(BookingRegistry::class, ['booking' => $s['booking']])
        ->assertDontSee('Mark Done');
});

/*
| ---------------------------------------------------------------------------
| 17. Atomicity.
| ---------------------------------------------------------------------------
*/

it('the Registry status change and Plot status change are atomic (17)', function () {
    $s = confirmedBookingScenario();

    $sql = [];
    DB::listen(fn ($q) => $sql[] = strtolower($q->sql));

    app(ChangeRegistryStatusAction::class)->handle($s['booking']->fresh(), RegistryStatus::Done, registryOfficer());

    $forUpdateCount = collect($sql)->filter(fn (string $q) => str_contains($q, 'for update'))->count();
    expect($forUpdateCount)->toBeGreaterThanOrEqual(2, 'MarkRegistryDoneAction must lock both the booking and the plot row FOR UPDATE');
})->skip(fn () => DB::connection()->getDriverName() === 'sqlite', 'SQLite has no row-level FOR UPDATE');

it('refuses to mark Done when the plot is not in a state that can become Sold, and touches nothing', function () {
    $s = confirmedBookingScenario();
    $s['booking']->plot->forceFill(['status' => PlotStatus::Cancelled])->save();

    expect(fn () => app(ChangeRegistryStatusAction::class)->handle($s['booking']->fresh(), RegistryStatus::Done, registryOfficer()))
        ->toThrow(DomainException::class);

    expect($s['booking']->fresh()->registry_status)->toBe(RegistryStatus::Pending)
        ->and($s['booking']->plot->fresh()->status)->toBe(PlotStatus::Cancelled);
});

it('is idempotent — marking an already-Done booking Done again is a safe no-op', function () {
    $s = confirmedBookingScenario();

    app(ChangeRegistryStatusAction::class)->handle($s['booking']->fresh(), RegistryStatus::Done, registryOfficer());
    $again = app(ChangeRegistryStatusAction::class)->handle($s['booking']->fresh(), RegistryStatus::Done, registryOfficer());

    expect($again->registry_status)->toBe(RegistryStatus::Done)
        ->and($s['booking']->plot->fresh()->status)->toBe(PlotStatus::Sold);
});

it('rejects changing Registry status for a booking that is not confirmed', function () {
    $s = bookingScenario();
    $booking = app(CreateBookingAction::class)->handle(bookingPayload($s), $s['actor']);

    expect(fn () => app(ChangeRegistryStatusAction::class)->handle($booking, RegistryStatus::Done, registryOfficer()))
        ->toThrow(DomainException::class, 'confirmed');
});

/*
| ---------------------------------------------------------------------------
| Audit trail.
| ---------------------------------------------------------------------------
*/

it('records who/when/previous/new status when Registry is marked Done', function () {
    $s = confirmedBookingScenario();
    $officer = registryOfficer();

    app(ChangeRegistryStatusAction::class)->handle($s['booking']->fresh(), RegistryStatus::Done, $officer);

    $event = DocumentActivity::where('booking_id', $s['booking']->id)->latest('id')->first();
    expect($event)->not->toBeNull()
        ->and($event->causer_id)->toBe($officer->id)
        ->and($event->created_at)->not->toBeNull()
        ->and($event->properties['from'])->toBe('pending')
        ->and($event->properties['to'])->toBe('done');
});

/*
| ---------------------------------------------------------------------------
| 21-22. Cancellation interaction.
| ---------------------------------------------------------------------------
*/

it('booking cancellation works with Registry Pending (21)', function () {
    $s = confirmedBookingScenario();

    $cancelled = app(CancelBookingAction::class)->handle($s['booking']->fresh(), $s['actor'], 'buyer withdrew');

    expect($cancelled->status)->toBe(BookingStatus::Cancelled)
        ->and($s['booking']->plot->fresh()->status)->toBe(PlotStatus::Available);
});

it('historical data is preserved when a Registry-Pending booking is cancelled (22)', function () {
    $s = confirmedBookingScenario();
    $booking = $s['booking'];

    app(CancelBookingAction::class)->handle($booking->fresh(), $s['actor'], 'buyer withdrew');

    expect(Booking::find($booking->id))->not->toBeNull()
        ->and(Buyer::find($s['buyer']->id))->not->toBeNull()
        ->and($booking->fresh()->registry_status)->toBe(RegistryStatus::Pending);
});
