<?php

declare(strict_types=1);

use App\Actions\Bookings\CreateBookingAction;
use App\Actions\Commission\GenerateCommissionCases;
use App\Actions\Partners\AuthorizePartnerForProjectAction;
use App\Actions\Partners\SetBookingPartnerAttribution;
use App\Actions\Registry\MarkRegistryDoneAction;
use App\Actions\Transfer\CreateTransferRequestAction;
use App\Actions\Transfer\ExecutePlotTransferAction;
use App\Enums\CommissionCaseStatus;
use App\Enums\PlotStatus;
use App\Enums\RegistryStatus;
use App\Enums\TransferRequestStatus;
use App\Enums\TransferType;
use App\Exceptions\DomainException;
use App\Livewire\Bookings\BookingTransfers;
use App\Models\Booking;
use App\Models\Buyer;
use App\Models\Document;
use App\Models\Partner;
use App\Models\Payment;
use App\Models\Plot;
use App\Models\TransferRequest;
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
| The exact BK-000002 scenario.
| ---------------------------------------------------------------------------
*/

it('BK-000002 scenario: transfers Buyer A from Plot 1 to Plot 3, preserving everything else', function () {
    $s = plotTransferReadyScenario();
    $booking = $s['booking'];

    expect($s['oldPlot']->fresh()->status)->toBe(PlotStatus::Booked)
        ->and($s['newPlot']->fresh()->status)->toBe(PlotStatus::Available);

    $transfer = app(ExecutePlotTransferAction::class)->handle($booking->fresh(), $s['newPlot']->id, 'buyer requested a corner plot', possessionOfficer());

    expect($booking->fresh()->plot_id)->toBe($s['newPlot']->id)
        ->and($booking->fresh()->id)->toBe($booking->id)
        ->and($booking->fresh()->bookingBuyers()->where('buyer_id', $s['buyer']->id)->exists())->toBeTrue()
        ->and($s['oldPlot']->fresh()->status)->toBe(PlotStatus::Available)
        ->and($s['newPlot']->fresh()->status)->toBe(PlotStatus::Booked)
        ->and($transfer->status)->toBe(TransferRequestStatus::Completed)
        ->and($transfer->transfer_type)->toBe(TransferType::PlotTransfer)
        ->and($transfer->plot_id)->toBe($s['oldPlot']->id)
        ->and($transfer->new_plot_id)->toBe($s['newPlot']->id);
});

/*
| ---------------------------------------------------------------------------
| 1-6. Target plot selection rules.
| ---------------------------------------------------------------------------
*/

it('an available target plot in the same project can be selected (1)', function () {
    $s = plotTransferReadyScenario();

    $transfer = app(ExecutePlotTransferAction::class)->handle($s['booking']->fresh(), $s['newPlot']->id, null, possessionOfficer());

    expect($transfer->status)->toBe(TransferRequestStatus::Completed);
});

it('the current plot cannot be selected as the target (2)', function () {
    $s = plotTransferReadyScenario();

    expect(fn () => app(ExecutePlotTransferAction::class)->handle($s['booking']->fresh(), $s['oldPlot']->id, null, possessionOfficer()))
        ->toThrow(DomainException::class);

    expect($s['booking']->fresh()->plot_id)->toBe($s['oldPlot']->id);
});

it('a target plot from another project cannot be selected (3)', function () {
    $s = plotTransferReadyScenario();
    $otherProjectPlot = Plot::factory()->create(['status' => PlotStatus::Available->value]);

    expect(fn () => app(ExecutePlotTransferAction::class)->handle($s['booking']->fresh(), $otherProjectPlot->id, null, possessionOfficer()))
        ->toThrow(DomainException::class, 'not eligible');

    expect($s['booking']->fresh()->plot_id)->toBe($s['oldPlot']->id)
        ->and($otherProjectPlot->fresh()->status)->toBe(PlotStatus::Available);
});

it('a booked target plot cannot be selected (4)', function () {
    $s = plotTransferReadyScenario();
    $s['newPlot']->forceFill(['status' => PlotStatus::Booked])->save();

    expect(fn () => app(ExecutePlotTransferAction::class)->handle($s['booking']->fresh(), $s['newPlot']->id, null, possessionOfficer()))
        ->toThrow(DomainException::class, 'not eligible');

    expect($s['booking']->fresh()->plot_id)->toBe($s['oldPlot']->id);
});

it('a held target plot cannot be selected (5)', function () {
    $s = plotTransferReadyScenario();
    $s['newPlot']->forceFill(['status' => PlotStatus::Hold->value])->save();

    expect(fn () => app(ExecutePlotTransferAction::class)->handle($s['booking']->fresh(), $s['newPlot']->id, null, possessionOfficer()))
        ->toThrow(DomainException::class, 'not eligible');

    expect($s['booking']->fresh()->plot_id)->toBe($s['oldPlot']->id);
});

it('a target plot that is already the target of another active transfer cannot be selected (6)', function () {
    $s = plotTransferReadyScenario();
    TransferRequest::factory()->forBooking($s['booking'])->create([
        'new_plot_id' => $s['newPlot']->id,
        'status' => TransferRequestStatus::UnderReview->value,
        'transfer_type' => TransferType::PlotTransfer->value,
    ]);

    expect(fn () => app(ExecutePlotTransferAction::class)->handle($s['booking']->fresh(), $s['newPlot']->id, null, possessionOfficer()))
        ->toThrow(DomainException::class, 'not eligible');
});

/*
| ---------------------------------------------------------------------------
| 7-9. Successful transfer state transitions.
| ---------------------------------------------------------------------------
*/

it('a successful transfer updates booking.plot_id (7)', function () {
    $s = plotTransferReadyScenario();

    app(ExecutePlotTransferAction::class)->handle($s['booking']->fresh(), $s['newPlot']->id, null, possessionOfficer());

    expect($s['booking']->fresh()->plot_id)->toBe($s['newPlot']->id)
        ->and($s['booking']->fresh()->block_id)->toBe($s['newPlot']->block_id)
        ->and($s['booking']->fresh()->project_id)->toBe($s['newPlot']->project_id);
});

it('a successful transfer releases the old plot (8)', function () {
    $s = plotTransferReadyScenario();

    app(ExecutePlotTransferAction::class)->handle($s['booking']->fresh(), $s['newPlot']->id, null, possessionOfficer());

    expect($s['oldPlot']->fresh()->status)->toBe(PlotStatus::Available);
});

it('a successful transfer books the new plot (9)', function () {
    $s = plotTransferReadyScenario();

    app(ExecutePlotTransferAction::class)->handle($s['booking']->fresh(), $s['newPlot']->id, null, possessionOfficer());

    expect($s['newPlot']->fresh()->status)->toBe(PlotStatus::Booked);
});

/*
| ---------------------------------------------------------------------------
| 10-16. Everything else on the booking is preserved untouched.
| ---------------------------------------------------------------------------
*/

it('the buyer remains unchanged (10)', function () {
    $s = plotTransferReadyScenario();
    $before = $s['booking']->bookingBuyers()->pluck('buyer_id')->sort()->values();

    app(ExecutePlotTransferAction::class)->handle($s['booking']->fresh(), $s['newPlot']->id, null, possessionOfficer());

    $after = $s['booking']->fresh()->bookingBuyers()->pluck('buyer_id')->sort()->values();
    expect($after->all())->toBe($before->all());
});

it('the booking ID remains unchanged (11)', function () {
    $s = plotTransferReadyScenario();
    $id = $s['booking']->id;
    $bookingNumber = $s['booking']->booking_number;

    app(ExecutePlotTransferAction::class)->handle($s['booking']->fresh(), $s['newPlot']->id, null, possessionOfficer());

    expect(Booking::count())->toBe(1)
        ->and($s['booking']->fresh()->id)->toBe($id)
        ->and($s['booking']->fresh()->booking_number)->toBe($bookingNumber);
});

it('payments remain unchanged (12)', function () {
    $s = plotTransferReadyScenario();
    $payment = payIn($s['booking'], $s['actor'], '100000', now()->toDateString());

    app(ExecutePlotTransferAction::class)->handle($s['booking']->fresh(), $s['newPlot']->id, null, possessionOfficer());

    $fresh = Payment::find($payment->id);
    expect($fresh)->not->toBeNull()
        ->and($fresh->amount)->toBe($payment->amount)
        ->and($fresh->status)->toBe($payment->status)
        ->and($fresh->booking_id)->toBe($s['booking']->id);
});

it('documents remain unchanged (13)', function () {
    $s = plotTransferReadyScenario();
    $document = Document::factory()->verified()->forDocumentable($s['booking'])
        ->state(['document_type_id' => docType('BOOKING_FORM')->id])->create();

    app(ExecutePlotTransferAction::class)->handle($s['booking']->fresh(), $s['newPlot']->id, null, possessionOfficer());

    expect(Document::find($document->id))->not->toBeNull()
        ->and(Document::find($document->id)->documentable_id)->toBe($s['booking']->id);
});

it('Registry status remains unchanged (14)', function () {
    $s = plotTransferReadyScenario();
    expect($s['booking']->fresh()->registry_status)->toBe(RegistryStatus::Pending);

    app(ExecutePlotTransferAction::class)->handle($s['booking']->fresh(), $s['newPlot']->id, null, possessionOfficer());

    expect($s['booking']->fresh()->registry_status)->toBe(RegistryStatus::Pending);
});

it('Possession status remains unchanged (15)', function () {
    $s = plotTransferReadyScenario();
    expect($s['booking']->fresh()->possession_status->value)->toBe('pending');

    app(ExecutePlotTransferAction::class)->handle($s['booking']->fresh(), $s['newPlot']->id, null, possessionOfficer());

    expect($s['booking']->fresh()->possession_status->value)->toBe('pending');
});

it('promoter attribution and commission history remain unchanged (16)', function () {
    $s = plotTransferReadyScenario('5000000');
    $actor = $s['actor'];
    $partner = Partner::factory()->active()->commission('2')->create();
    app(AuthorizePartnerForProjectAction::class)->handle($partner, $s['booking']->project, $actor);
    app(SetBookingPartnerAttribution::class)->handle($s['booking'], $partner->id, $actor);
    $case = app(GenerateCommissionCases::class)->handle($s['booking']->fresh(), $actor)->first();

    app(ExecutePlotTransferAction::class)->handle($s['booking']->fresh(), $s['newPlot']->id, null, possessionOfficer());

    expect($s['booking']->fresh()->promoterAttribution?->partner_id)->toBe($partner->id)
        ->and($case->fresh()->status)->toBe(CommissionCaseStatus::PendingReview)
        ->and($case->fresh()->commission_amount)->toBe($case->commission_amount);
});

/*
| ---------------------------------------------------------------------------
| 17. Transfer history.
| ---------------------------------------------------------------------------
*/

it('creates an append-only Transfer History record (17)', function () {
    $s = plotTransferReadyScenario();

    $transfer = app(ExecutePlotTransferAction::class)->handle($s['booking']->fresh(), $s['newPlot']->id, 'moving closer to the entrance', possessionOfficer());

    expect(TransferRequest::count())->toBe(1)
        ->and($transfer->booking_id)->toBe($s['booking']->id)
        ->and($transfer->plot_id)->toBe($s['oldPlot']->id)
        ->and($transfer->new_plot_id)->toBe($s['newPlot']->id)
        ->and($transfer->reason)->toBe('moving closer to the entrance')
        ->and($transfer->completed_by)->not->toBeNull()
        ->and($transfer->completed_at)->not->toBeNull()
        ->and($transfer->status)->toBe(TransferRequestStatus::Completed);
});

/*
| ---------------------------------------------------------------------------
| 18. Atomicity.
| ---------------------------------------------------------------------------
*/

it('is atomic — an ineligible transfer touches nothing at all (18)', function () {
    $s = plotTransferReadyScenario();
    $s['newPlot']->forceFill(['status' => PlotStatus::Booked])->save();

    expect(fn () => app(ExecutePlotTransferAction::class)->handle($s['booking']->fresh(), $s['newPlot']->id, null, possessionOfficer()))
        ->toThrow(DomainException::class);

    expect(TransferRequest::count())->toBe(0)
        ->and($s['booking']->fresh()->plot_id)->toBe($s['oldPlot']->id)
        ->and($s['oldPlot']->fresh()->status)->toBe(PlotStatus::Booked);
});

it('locks the booking and both plots FOR UPDATE (18)', function () {
    $s = plotTransferReadyScenario();

    $sql = [];
    DB::listen(fn ($q) => $sql[] = strtolower($q->sql));

    app(ExecutePlotTransferAction::class)->handle($s['booking']->fresh(), $s['newPlot']->id, null, possessionOfficer());

    $forUpdateCount = collect($sql)->filter(fn (string $q) => str_contains($q, 'for update'))->count();
    expect($forUpdateCount)->toBeGreaterThanOrEqual(3, 'must lock the booking and both plots FOR UPDATE');
})->skip(fn () => DB::connection()->getDriverName() === 'sqlite', 'SQLite has no row-level FOR UPDATE');

/*
| ---------------------------------------------------------------------------
| 19. Concurrency — the second of two simultaneous transfers to the same
| target must fail cleanly.
| ---------------------------------------------------------------------------
*/

it('a concurrent transfer to an already-claimed target plot cannot double-book it (19)', function () {
    $s = plotTransferReadyScenario();
    $officer = possessionOfficer();

    // First transfer claims the target plot.
    app(ExecutePlotTransferAction::class)->handle($s['booking']->fresh(), $s['newPlot']->id, null, $officer);

    // A second booking + transfer attempt (simulating a second operator)
    // targeting the SAME now-booked plot must fail cleanly, never double-book it.
    $s2 = plotTransferReadyScenario();
    expect(fn () => app(ExecutePlotTransferAction::class)->handle($s2['booking']->fresh(), $s['newPlot']->id, null, $officer))
        ->toThrow(DomainException::class);

    expect($s['newPlot']->fresh()->status)->toBe(PlotStatus::Booked)
        ->and(Booking::where('plot_id', $s['newPlot']->id)->whereIn('status', ['pending', 'confirmed'])->count())->toBe(1);
});

/*
| ---------------------------------------------------------------------------
| 20. Authorization.
| ---------------------------------------------------------------------------
*/

it('an unauthorised user cannot execute a plot transfer (20)', function () {
    $s = plotTransferReadyScenario();
    $noPermission = makeUser(permissions: ['transfer.view', 'bookings.view']);

    expect(fn () => app(ExecutePlotTransferAction::class)->handle($s['booking']->fresh(), $s['newPlot']->id, null, $noPermission))
        ->toThrow(DomainException::class, 'not authorised');

    expect($s['booking']->fresh()->plot_id)->toBe($s['oldPlot']->id);
});

it('the Transfer Plot button is hidden from a user without transfer.complete', function () {
    $s = plotTransferReadyScenario();
    $noComplete = makeUser(permissions: ['transfer.view', 'bookings.view']);

    Livewire::actingAs($noComplete)
        ->test(BookingTransfers::class, ['booking' => $s['booking']])
        ->assertDontSee('Transfer Plot');
});

it('the Transfers screen has no ownership/nominee UI', function () {
    $s = plotTransferReadyScenario();

    $html = Livewire::actingAs(possessionOfficer())
        ->test(BookingTransfers::class, ['booking' => $s['booking']])
        ->assertOk()
        ->html();

    expect($html)->not->toContain('Owner change')
        ->not->toContain('Family transfer')
        ->not->toContain('Sale transfer')
        ->not->toContain('Legal transfer')
        ->not->toContain('Nominee change')
        ->not->toContain('Current owner')
        ->not->toContain('Transfer Application')
        ->not->toContain('Transfer Consent')
        ->not->toContain('Transfer ID Proof');
});

/*
| ---------------------------------------------------------------------------
| Registry-Done conflict (audited business rule, new guard).
| ---------------------------------------------------------------------------
*/

it('refuses a plot transfer once Registry is already Done for the booking', function () {
    $s = plotTransferReadyScenario();
    app(MarkRegistryDoneAction::class)->handle($s['booking']->fresh(), registryOfficer());
    expect($s['oldPlot']->fresh()->status)->toBe(PlotStatus::Sold);

    expect(fn () => app(ExecutePlotTransferAction::class)->handle($s['booking']->fresh(), $s['newPlot']->id, null, possessionOfficer()))
        ->toThrow(DomainException::class, 'not eligible');

    expect($s['booking']->fresh()->plot_id)->toBe($s['oldPlot']->id)
        ->and($s['oldPlot']->fresh()->status)->toBe(PlotStatus::Sold);
});

/*
| ---------------------------------------------------------------------------
| 21. Historical (old-workflow) transfer records are preserved.
| ---------------------------------------------------------------------------
*/

it('existing historical ownership-transfer records are preserved untouched (21)', function () {
    $s = plotTransferReadyScenario();
    $historical = TransferRequest::factory()->forBooking($s['booking'])->status(TransferRequestStatus::Rejected)->create([
        'transfer_type' => TransferType::SaleTransfer->value,
        'rejection_reason' => 'incomplete KYC',
    ]);

    app(ExecutePlotTransferAction::class)->handle($s['booking']->fresh(), $s['newPlot']->id, null, possessionOfficer());

    expect(TransferRequest::find($historical->id))->not->toBeNull()
        ->and(TransferRequest::find($historical->id)->status)->toBe(TransferRequestStatus::Rejected)
        ->and(TransferRequest::find($historical->id)->rejection_reason)->toBe('incomplete KYC');
});

it('rejects raising a NEW ownership/nominee transfer type through the old creation action just as before (still intact)', function () {
    $s = plotTransferReadyScenario();

    expect(fn () => app(CreateTransferRequestAction::class)->handle(
        $s['booking']->fresh(), TransferType::SaleTransfer, [], possessionOfficer()
    ))->toThrow(DomainException::class, 'incoming buyer');
});

/*
| ---------------------------------------------------------------------------
| Idempotency / no reintroduced buyer/type fields exposed.
| ---------------------------------------------------------------------------
*/

it('rejects an unconfirmed booking', function () {
    $s = bookingScenario();
    $booking = app(CreateBookingAction::class)->handle(bookingPayload($s), $s['actor']);
    $otherPlot = Plot::factory()->create(['project_id' => $s['project']->id, 'block_id' => $s['block']->id, 'status' => PlotStatus::Available->value]);

    expect(fn () => app(ExecutePlotTransferAction::class)->handle($booking, $otherPlot->id, null, possessionOfficer()))
        ->toThrow(DomainException::class, 'confirmed');
});
