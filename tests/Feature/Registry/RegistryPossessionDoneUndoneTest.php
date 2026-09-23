<?php

declare(strict_types=1);

use App\Actions\Bookings\CancelBookingAction;
use App\Actions\Commission\GenerateCommissionCases;
use App\Actions\Partners\AuthorizePartnerForProjectAction;
use App\Actions\Partners\SetBookingPartnerAttribution;
use App\Actions\Possession\ChangePossessionStatusAction;
use App\Actions\Registry\ChangeRegistryStatusAction;
use App\Actions\Transfer\ExecutePlotTransferAction;
use App\Enums\BookingStatus;
use App\Enums\CommissionCaseStatus;
use App\Enums\DocumentActivityType;
use App\Enums\PlotStatus;
use App\Enums\PossessionStatus;
use App\Enums\RegistryStatus;
use App\Enums\TransferRequestStatus;
use App\Exceptions\DomainException;
use App\Livewire\Bookings\BookingPossession;
use App\Livewire\Bookings\BookingRegistry;
use App\Models\Booking;
use App\Models\Document;
use App\Models\DocumentActivity;
use App\Models\Partner;
use App\Models\Payment;
use App\Models\Plot;
use App\Models\PlotOwnershipHistory;
use App\Models\TransferRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    seedRbac();
    seedDocumentMasters();
    Storage::fake('documents');
});

function registryTo(Booking $booking, RegistryStatus $target): Booking
{
    return app(ChangeRegistryStatusAction::class)->handle($booking->fresh(), $target, registryOfficer());
}

function possessionTo(Booking $booking, PossessionStatus $target): Booking
{
    return app(ChangePossessionStatusAction::class)->handle($booking->fresh(), $target, possessionOfficer());
}

function transferTo(Booking $booking, Plot $plot): TransferRequest
{
    return app(ExecutePlotTransferAction::class)->handle($booking->fresh(), $plot->id, null, possessionOfficer());
}

/*
| ---------------------------------------------------------------------------
| REGISTRY — Pending → Done → Undone → Done (1-6)
| ---------------------------------------------------------------------------
*/

it('Registry Pending → Done sets the current plot Sold (1, 4)', function () {
    $s = plotTransferReadyScenario();

    $booking = registryTo($s['booking'], RegistryStatus::Done);

    expect($booking->registry_status)->toBe(RegistryStatus::Done)
        ->and($s['oldPlot']->fresh()->status)->toBe(PlotStatus::Sold);
});

it('Registry Done → Undone sets the current plot back to Booked (2, 5)', function () {
    $s = plotTransferReadyScenario();
    registryTo($s['booking'], RegistryStatus::Done);

    $booking = registryTo($s['booking'], RegistryStatus::Undone);

    expect($booking->registry_status)->toBe(RegistryStatus::Undone)
        ->and($s['oldPlot']->fresh()->status)->toBe(PlotStatus::Booked);
});

it('Registry Undone → Done sets the current plot Sold again (3)', function () {
    $s = plotTransferReadyScenario();
    registryTo($s['booking'], RegistryStatus::Done);
    registryTo($s['booking'], RegistryStatus::Undone);

    $booking = registryTo($s['booking'], RegistryStatus::Done);

    expect($booking->registry_status)->toBe(RegistryStatus::Done)
        ->and($s['oldPlot']->fresh()->status)->toBe(PlotStatus::Sold);
});

it('refuses transitions outside the Registry state machine and touches nothing', function () {
    $s = plotTransferReadyScenario();

    // Pending → Undone is not a valid transition.
    expect(fn () => registryTo($s['booking'], RegistryStatus::Undone))
        ->toThrow(DomainException::class, 'cannot move from Pending to Undone');

    registryTo($s['booking'], RegistryStatus::Done);

    // Nothing ever goes back to Pending.
    expect(fn () => registryTo($s['booking'], RegistryStatus::Pending))
        ->toThrow(DomainException::class);

    expect($s['booking']->fresh()->registry_status)->toBe(RegistryStatus::Done)
        ->and($s['oldPlot']->fresh()->status)->toBe(PlotStatus::Sold);
});

it('Registry Undone is idempotent', function () {
    $s = plotTransferReadyScenario();
    registryTo($s['booking'], RegistryStatus::Done);
    registryTo($s['booking'], RegistryStatus::Undone);

    $again = registryTo($s['booking'], RegistryStatus::Undone);

    expect($again->registry_status)->toBe(RegistryStatus::Undone)
        ->and($s['oldPlot']->fresh()->status)->toBe(PlotStatus::Booked)
        ->and(DocumentActivity::where('type', DocumentActivityType::RegistryStatusChanged)->count())->toBe(2);
});

it('Registry Undone refuses a plot that is not Sold/Booked (e.g. legacy Possession completed), touching nothing', function () {
    $s = plotTransferReadyScenario();
    registryTo($s['booking'], RegistryStatus::Done);
    $s['oldPlot']->forceFill(['status' => PlotStatus::PossessionCompleted])->save();

    expect(fn () => registryTo($s['booking'], RegistryStatus::Undone))->toThrow(DomainException::class);

    expect($s['booking']->fresh()->registry_status)->toBe(RegistryStatus::Done)
        ->and($s['oldPlot']->fresh()->status)->toBe(PlotStatus::PossessionCompleted);
});

it('Registry Done/Undone records history with from/to', function () {
    $s = plotTransferReadyScenario();
    registryTo($s['booking'], RegistryStatus::Done);
    registryTo($s['booking'], RegistryStatus::Undone);

    $events = DocumentActivity::where('booking_id', $s['booking']->id)
        ->where('type', DocumentActivityType::RegistryStatusChanged)->orderBy('id')->get();

    expect($events)->toHaveCount(2)
        ->and($events[0]->properties)->toMatchArray(['from' => 'pending', 'to' => 'done'])
        ->and($events[1]->properties)->toMatchArray(['from' => 'done', 'to' => 'undone']);
});

it('after a Plot Transfer, Registry Done/Undone affects ONLY the current plot (6, 7, 8)', function () {
    $s = plotTransferReadyScenario();
    registryTo($s['booking'], RegistryStatus::Done);
    transferTo($s['booking'], $s['newPlot']);

    expect($s['oldPlot']->fresh()->status)->toBe(PlotStatus::Available)
        ->and($s['newPlot']->fresh()->status)->toBe(PlotStatus::Sold);

    registryTo($s['booking'], RegistryStatus::Undone);

    expect($s['newPlot']->fresh()->status)->toBe(PlotStatus::Booked)
        ->and($s['oldPlot']->fresh()->status)->toBe(PlotStatus::Available);

    registryTo($s['booking'], RegistryStatus::Done);

    expect($s['newPlot']->fresh()->status)->toBe(PlotStatus::Sold)
        ->and($s['oldPlot']->fresh()->status)->toBe(PlotStatus::Available);
});

it('Registry screen shows Mark Done when Pending/Undone and Mark Undone when Done', function () {
    $s = plotTransferReadyScenario();
    $officer = registryOfficer();

    $component = Livewire::actingAs($officer)->test(BookingRegistry::class, ['booking' => $s['booking']])
        ->assertSee('Mark Done')->assertDontSee('Mark Undone');

    $component->call('markDone')
        ->assertSee('Mark Undone')->assertDontSee('Mark Done');
    expect($s['oldPlot']->fresh()->status)->toBe(PlotStatus::Sold);

    $component->call('markUndone')
        ->assertSee('Undone')->assertSee('Mark Done');
    expect($s['oldPlot']->fresh()->status)->toBe(PlotStatus::Booked)
        ->and($s['booking']->fresh()->registry_status)->toBe(RegistryStatus::Undone);
});

/*
| ---------------------------------------------------------------------------
| POSSESSION — Pending → Done → Undone → Done (7-13)
| ---------------------------------------------------------------------------
*/

it('Possession Pending → Done → Undone → Done (7, 8, 9)', function () {
    $s = plotTransferReadyScenario();

    expect(possessionTo($s['booking'], PossessionStatus::Done)->possession_status)->toBe(PossessionStatus::Done)
        ->and(possessionTo($s['booking'], PossessionStatus::Undone)->possession_status)->toBe(PossessionStatus::Undone)
        ->and(possessionTo($s['booking'], PossessionStatus::Done)->possession_status)->toBe(PossessionStatus::Done);
});

it('Possession never changes plot status or Registry (10)', function () {
    $s = plotTransferReadyScenario();

    possessionTo($s['booking'], PossessionStatus::Done);
    expect($s['oldPlot']->fresh()->status)->toBe(PlotStatus::Booked);

    possessionTo($s['booking'], PossessionStatus::Undone);
    expect($s['oldPlot']->fresh()->status)->toBe(PlotStatus::Booked);

    registryTo($s['booking'], RegistryStatus::Done);
    possessionTo($s['booking'], PossessionStatus::Done);
    possessionTo($s['booking'], PossessionStatus::Undone);

    $booking = $s['booking']->fresh();
    expect($booking->registry_status)->toBe(RegistryStatus::Done)
        ->and($booking->possession_status)->toBe(PossessionStatus::Undone)
        ->and($s['oldPlot']->fresh()->status)->toBe(PlotStatus::Sold);
});

it('Possession does not require Registry, payments or documents (11, 12, 13)', function () {
    $s = plotTransferReadyScenario();

    expect($s['booking']->fresh()->registry_status)->toBe(RegistryStatus::Pending)
        ->and(Payment::where('booking_id', $s['booking']->id)->count())->toBe(0)
        ->and(Document::count())->toBe(0);

    expect(possessionTo($s['booking'], PossessionStatus::Done)->possession_status)->toBe(PossessionStatus::Done);
});

it('refuses Possession Pending → Undone', function () {
    $s = plotTransferReadyScenario();

    expect(fn () => possessionTo($s['booking'], PossessionStatus::Undone))
        ->toThrow(DomainException::class, 'cannot move from Pending to Undone');
});

it('Possession screen shows Mark Done / Mark Undone with no eligibility UI', function () {
    $s = plotTransferReadyScenario();

    $component = Livewire::actingAs(possessionOfficer())->test(BookingPossession::class, ['booking' => $s['booking']])
        ->assertSee('Mark Done')->assertDontSee('Mark Undone')
        ->assertDontSee('Eligibility')->assertDontSee('Open possession case');

    $component->call('markDone')->assertSee('Mark Undone');
    $component->call('markUndone')->assertSee('Mark Done');

    expect($s['booking']->fresh()->possession_status)->toBe(PossessionStatus::Undone)
        ->and($s['oldPlot']->fresh()->status)->toBe(PlotStatus::Booked);
});

/*
| ---------------------------------------------------------------------------
| PLOT TRANSFER with Done states (14-29)
| ---------------------------------------------------------------------------
*/

it('CASE A: Registry Pending → old Available, new Booked (14, 17, 18)', function () {
    $s = plotTransferReadyScenario();

    transferTo($s['booking'], $s['newPlot']);

    expect($s['oldPlot']->fresh()->status)->toBe(PlotStatus::Available)
        ->and($s['newPlot']->fresh()->status)->toBe(PlotStatus::Booked);
});

it('CASE A (Undone): Registry Undone → new plot Booked', function () {
    $s = plotTransferReadyScenario();
    registryTo($s['booking'], RegistryStatus::Done);
    registryTo($s['booking'], RegistryStatus::Undone);

    transferTo($s['booking'], $s['newPlot']);

    expect($s['oldPlot']->fresh()->status)->toBe(PlotStatus::Available)
        ->and($s['newPlot']->fresh()->status)->toBe(PlotStatus::Booked)
        ->and($s['booking']->fresh()->registry_status)->toBe(RegistryStatus::Undone);
});

it('CASE B: Registry Done → transfer allowed, old Available, new Sold (15, 19)', function () {
    $s = plotTransferReadyScenario();
    registryTo($s['booking'], RegistryStatus::Done);

    $transfer = transferTo($s['booking'], $s['newPlot']);

    expect($transfer->status)->toBe(TransferRequestStatus::Completed)
        ->and($s['oldPlot']->fresh()->status)->toBe(PlotStatus::Available)
        ->and($s['newPlot']->fresh()->status)->toBe(PlotStatus::Sold)
        ->and($s['booking']->fresh()->registry_status)->toBe(RegistryStatus::Done);
});

it('CASE C: Registry Done + Possession Done → allowed, statuses preserved, never reset to Pending (16, 24, 25)', function () {
    $s = plotTransferReadyScenario();
    registryTo($s['booking'], RegistryStatus::Done);
    possessionTo($s['booking'], PossessionStatus::Done);

    transferTo($s['booking'], $s['newPlot']);

    $booking = $s['booking']->fresh();
    expect($booking->plot_id)->toBe($s['newPlot']->id)
        ->and($booking->registry_status)->toBe(RegistryStatus::Done)
        ->and($booking->possession_status)->toBe(PossessionStatus::Done)
        ->and($s['oldPlot']->fresh()->status)->toBe(PlotStatus::Available)
        ->and($s['newPlot']->fresh()->status)->toBe(PlotStatus::Sold);
});

it('Possession Done alone does not block a transfer; new plot stays Booked (16)', function () {
    $s = plotTransferReadyScenario();
    possessionTo($s['booking'], PossessionStatus::Done);

    transferTo($s['booking'], $s['newPlot']);

    expect($s['newPlot']->fresh()->status)->toBe(PlotStatus::Booked)
        ->and($s['booking']->fresh()->possession_status)->toBe(PossessionStatus::Done);
});

it('preserves booking, buyer, payments, documents and block from target plot (20-23)', function () {
    $s = plotTransferReadyScenario();
    $payment = payIn($s['booking'], $s['actor'], '100000', now()->toDateString());
    $document = Document::factory()->verified()->forDocumentable($s['booking'])
        ->state(['document_type_id' => docType('BOOKING_FORM')->id])->create();
    registryTo($s['booking'], RegistryStatus::Done);
    possessionTo($s['booking'], PossessionStatus::Done);
    $buyersBefore = $s['booking']->bookingBuyers()->pluck('buyer_id')->all();

    transferTo($s['booking'], $s['newPlot']);

    $booking = $s['booking']->fresh();
    expect(Booking::count())->toBe(1)
        ->and($booking->id)->toBe($s['booking']->id)
        ->and($booking->booking_number)->toBe($s['booking']->booking_number)
        ->and($booking->block_id)->toBe($s['newPlot']->block_id)
        ->and($booking->bookingBuyers()->pluck('buyer_id')->all())->toBe($buyersBefore)
        ->and(Payment::find($payment->id)->booking_id)->toBe($booking->id)
        ->and(Payment::find($payment->id)->amount)->toBe($payment->amount)
        ->and(Payment::find($payment->id)->status)->toBe($payment->status)
        ->and(Document::find($document->id)->documentable_id)->toBe($booking->id);
});

it('copies a NULL block from a block-less target plot', function () {
    $s = plotTransferReadyScenario();
    $s['newPlot']->forceFill(['block_id' => null])->save();

    transferTo($s['booking'], $s['newPlot']);

    expect($s['booking']->fresh()->block_id)->toBeNull();
});

it('preserves promoter attribution and commission with Registry Done (26)', function () {
    $s = plotTransferReadyScenario('5000000');
    $actor = $s['actor'];
    $partner = Partner::factory()->active()->commission('2')->create();
    app(AuthorizePartnerForProjectAction::class)->handle($partner, $s['booking']->project, $actor);
    app(SetBookingPartnerAttribution::class)->handle($s['booking'], $partner->id, $actor);
    $case = app(GenerateCommissionCases::class)->handle($s['booking']->fresh(), $actor)->first();
    registryTo($s['booking'], RegistryStatus::Done);

    transferTo($s['booking'], $s['newPlot']);

    expect($s['booking']->fresh()->promoterAttribution?->partner_id)->toBe($partner->id)
        ->and($case->fresh()->status)->toBe(CommissionCaseStatus::PendingReview)
        ->and($case->fresh()->commission_amount)->toBe($case->commission_amount);
});

it('creates transfer history and moves the current ownership period, keeping the old one (27)', function () {
    $s = plotTransferReadyScenario();
    registryTo($s['booking'], RegistryStatus::Done);

    $transfer = transferTo($s['booking'], $s['newPlot']);

    expect(TransferRequest::count())->toBe(1)
        ->and($transfer->plot_id)->toBe($s['oldPlot']->id)
        ->and($transfer->new_plot_id)->toBe($s['newPlot']->id)
        ->and($transfer->status)->toBe(TransferRequestStatus::Completed);

    $periods = PlotOwnershipHistory::where('booking_id', $s['booking']->id)->orderBy('id')->get();
    expect($periods->whereNull('ended_at')->pluck('plot_id')->unique()->all())->toBe([$s['newPlot']->id])
        ->and($periods->where('plot_id', $s['oldPlot']->id)->whereNotNull('ended_at'))->not->toBeEmpty();
});

it('rolls back everything when the transfer fails midway (28)', function () {
    $s = plotTransferReadyScenario();
    registryTo($s['booking'], RegistryStatus::Done);

    // Fail at the very last write — after both plots have been changed.
    Booking::saving(function (Booking $b): void {
        if ($b->isDirty('plot_id')) {
            throw new RuntimeException('simulated failure');
        }
    });

    expect(fn () => transferTo($s['booking'], $s['newPlot']))->toThrow(RuntimeException::class);

    expect(TransferRequest::count())->toBe(0)
        ->and($s['booking']->fresh()->plot_id)->toBe($s['oldPlot']->id)
        ->and($s['booking']->fresh()->registry_status)->toBe(RegistryStatus::Done)
        ->and($s['oldPlot']->fresh()->status)->toBe(PlotStatus::Sold)
        ->and($s['newPlot']->fresh()->status)->toBe(PlotStatus::Available);
});

it('prevents booking a target plot that was concurrently claimed (29)', function () {
    $s = plotTransferReadyScenario();
    registryTo($s['booking'], RegistryStatus::Done);

    // Another booking in the same project claims the target first.
    $otherPlot = Plot::factory()->create([
        'project_id' => $s['oldPlot']->project_id, 'block_id' => $s['oldPlot']->block_id,
        'status' => PlotStatus::Booked->value,
    ]);
    $other = Booking::factory()->confirmed()->forPlot($otherPlot)->create();
    $claimed = $s['newPlot'];
    transferTo($other, $claimed);

    expect(fn () => transferTo($s['booking'], $claimed))->toThrow(DomainException::class);

    expect($s['booking']->fresh()->plot_id)->toBe($s['oldPlot']->id)
        ->and($s['oldPlot']->fresh()->status)->toBe(PlotStatus::Sold)
        ->and($claimed->fresh()->status)->toBe(PlotStatus::Booked)
        ->and(Booking::where('plot_id', $claimed->id)->count())->toBe(1);
});

/*
| ---------------------------------------------------------------------------
| CANCELLATION (30-33)
| ---------------------------------------------------------------------------
*/

it('Registry Done gives an actionable cancellation blocker, not an impossible one', function () {
    $s = plotTransferReadyScenario();
    registryTo($s['booking'], RegistryStatus::Done);

    expect(fn () => app(CancelBookingAction::class)->handle($s['booking']->fresh(), $s['actor'], 'x'))
        ->toThrow(DomainException::class, 'mark Registry Undone first');

    registryTo($s['booking'], RegistryStatus::Undone);
    $cancelled = app(CancelBookingAction::class)->handle($s['booking']->fresh(), $s['actor'], 'buyer withdrew');

    expect($cancelled->status)->toBe(BookingStatus::Cancelled)
        ->and($s['oldPlot']->fresh()->status)->toBe(PlotStatus::Available);
});

it('Possession Done never blocks cancellation', function () {
    $s = plotTransferReadyScenario();
    possessionTo($s['booking'], PossessionStatus::Done);

    $cancelled = app(CancelBookingAction::class)->handle($s['booking']->fresh(), $s['actor'], 'x');

    expect($cancelled->status)->toBe(BookingStatus::Cancelled)
        ->and($cancelled->possession_status)->toBe(PossessionStatus::Done)
        ->and($s['oldPlot']->fresh()->status)->toBe(PlotStatus::Available);
});

it('cancelling after a transfer releases only the current plot and preserves all history (30-33)', function () {
    $s = plotTransferReadyScenario();
    $document = Document::factory()->verified()->forDocumentable($s['booking'])
        ->state(['document_type_id' => docType('BOOKING_FORM')->id])->create();
    registryTo($s['booking'], RegistryStatus::Done);
    $transfer = transferTo($s['booking'], $s['newPlot']);
    registryTo($s['booking'], RegistryStatus::Undone);

    $cancelled = app(CancelBookingAction::class)->handle($s['booking']->fresh(), $s['actor'], 'buyer withdrew');

    expect($cancelled->status)->toBe(BookingStatus::Cancelled)
        ->and(Booking::withTrashed()->find($s['booking']->id))->not->toBeNull()
        ->and($cancelled->plot_id)->toBe($s['newPlot']->id)
        ->and($cancelled->bookingBuyers()->where('buyer_id', $s['buyer']->id)->exists())->toBeTrue()
        ->and(Document::find($document->id))->not->toBeNull()
        ->and($s['newPlot']->fresh()->status)->toBe(PlotStatus::Available)
        ->and($s['oldPlot']->fresh()->status)->toBe(PlotStatus::Available)
        ->and(TransferRequest::find($transfer->id)->status)->toBe(TransferRequestStatus::Completed)
        ->and(DocumentActivity::where('booking_id', $s['booking']->id)
            ->where('type', DocumentActivityType::RegistryStatusChanged)->count())->toBe(2);
});
