<?php

declare(strict_types=1);

use App\Actions\Partners\AuthorizePartnerForProjectAction;
use App\Actions\Partners\RemoveBookingPartnerAttribution;
use App\Actions\Partners\SetBookingPartnerAttribution;
use App\Enums\BookingAttributionRole;
use App\Enums\PartnerActivityType;
use App\Enums\PartnerStatus;
use App\Exceptions\DomainException;
use App\Livewire\Bookings\BookingPartners;
use App\Models\Booking;
use App\Models\BookingPartnerAttribution;
use App\Models\Partner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(fn () => seedRbac());

/**
 * A confirmed booking + N active partners already authorised for its project.
 *
 * @return array{actor: User, booking: Booking, partners: array<int, Partner>}
 */
function bookingAttributionWorld(int $partnerCount = 2): array
{
    $s = confirmedBookingScenario();
    $actor = User::factory()->create();

    $partners = [];
    for ($i = 0; $i < $partnerCount; $i++) {
        $partner = Partner::factory()->active()->create();
        app(AuthorizePartnerForProjectAction::class)->handle($partner, $s['booking']->project, $actor);
        $partners[] = $partner;
    }

    return ['actor' => $actor, 'booking' => $s['booking']->fresh(), 'partners' => $partners];
}

it('attributes a single partner as an implicit 100% primary', function () {
    ['actor' => $actor, 'booking' => $booking, 'partners' => [$p]] = bookingAttributionWorld(1);

    app(SetBookingPartnerAttribution::class)->handle($booking, [
        ['partner_id' => $p->id, 'share_percentage' => '100'],
    ], $actor);

    $row = $booking->partnerAttributions()->sole();
    expect((float) $row->share_percentage)->toBe(100.0)
        ->and($row->role)->toBe(BookingAttributionRole::Primary)
        ->and($p->fresh()->activities()->where('type', PartnerActivityType::BookingAttributed->value)->exists())->toBeTrue();
});

it('accepts a co-broker split that totals exactly 100', function () {
    ['actor' => $actor, 'booking' => $booking, 'partners' => [$a, $b]] = bookingAttributionWorld(2);

    app(SetBookingPartnerAttribution::class)->handle($booking, [
        ['partner_id' => $a->id, 'share_percentage' => '62.50', 'role' => 'primary'],
        ['partner_id' => $b->id, 'share_percentage' => '37.50', 'role' => 'co_broker'],
    ], $actor);

    expect($booking->partnerAttributions()->count())->toBe(2)
        ->and((float) $booking->partnerAttributions()->sum('share_percentage'))->toBe(100.0);
});

it('rejects a split that does not total 100', function () {
    ['actor' => $actor, 'booking' => $booking, 'partners' => [$a, $b]] = bookingAttributionWorld(2);

    expect(fn () => app(SetBookingPartnerAttribution::class)->handle($booking, [
        ['partner_id' => $a->id, 'share_percentage' => '60', 'role' => 'primary'],
        ['partner_id' => $b->id, 'share_percentage' => '30', 'role' => 'co_broker'],
    ], $actor))->toThrow(DomainException::class, 'total exactly 100%');
});

it('rejects a split with no primary or more than one primary', function () {
    ['actor' => $actor, 'booking' => $booking, 'partners' => [$a, $b]] = bookingAttributionWorld(2);

    expect(fn () => app(SetBookingPartnerAttribution::class)->handle($booking, [
        ['partner_id' => $a->id, 'share_percentage' => '50', 'role' => 'co_broker'],
        ['partner_id' => $b->id, 'share_percentage' => '50', 'role' => 'co_broker'],
    ], $actor))->toThrow(DomainException::class, 'primary');

    expect(fn () => app(SetBookingPartnerAttribution::class)->handle($booking, [
        ['partner_id' => $a->id, 'share_percentage' => '50', 'role' => 'primary'],
        ['partner_id' => $b->id, 'share_percentage' => '50', 'role' => 'primary'],
    ], $actor))->toThrow(DomainException::class, 'primary');
});

it('rejects a duplicate partner in the split', function () {
    ['actor' => $actor, 'booking' => $booking, 'partners' => [$a]] = bookingAttributionWorld(1);

    expect(fn () => app(SetBookingPartnerAttribution::class)->handle($booking, [
        ['partner_id' => $a->id, 'share_percentage' => '50', 'role' => 'primary'],
        ['partner_id' => $a->id, 'share_percentage' => '50', 'role' => 'co_broker'],
    ], $actor))->toThrow(DomainException::class, 'once');
});

it('rejects a partner not authorised for the project', function () {
    ['actor' => $actor, 'booking' => $booking] = bookingAttributionWorld(0);
    $unauthorised = Partner::factory()->active()->create();

    expect(fn () => app(SetBookingPartnerAttribution::class)->handle($booking, [
        ['partner_id' => $unauthorised->id, 'share_percentage' => '100', 'role' => 'primary'],
    ], $actor))->toThrow(DomainException::class, 'not authorised');
});

it('rejects a non-active partner', function () {
    ['actor' => $actor, 'booking' => $booking] = bookingAttributionWorld(0);
    $partner = Partner::factory()->status(PartnerStatus::OnHold)->create();
    app(AuthorizePartnerForProjectAction::class)->handle($partner, $booking->project, $actor);

    expect(fn () => app(SetBookingPartnerAttribution::class)->handle($booking, [
        ['partner_id' => $partner->id, 'share_percentage' => '100', 'role' => 'primary'],
    ], $actor))->toThrow(DomainException::class);
});

it('supersedes the previous split and bumps the revision — history is kept', function () {
    ['actor' => $actor, 'booking' => $booking, 'partners' => [$a, $b]] = bookingAttributionWorld(2);

    app(SetBookingPartnerAttribution::class)->handle($booking, [
        ['partner_id' => $a->id, 'share_percentage' => '100', 'role' => 'primary'],
    ], $actor);

    app(SetBookingPartnerAttribution::class)->handle($booking->fresh(), [
        ['partner_id' => $a->id, 'share_percentage' => '70', 'role' => 'primary'],
        ['partner_id' => $b->id, 'share_percentage' => '30', 'role' => 'co_broker'],
    ], $actor);

    $all = BookingPartnerAttribution::where('booking_id', $booking->id)->get();
    expect($all)->toHaveCount(3)
        ->and($all->where('status', 'active')->count())->toBe(2)
        ->and($all->where('status', 'superseded')->count())->toBe(1)
        ->and($all->where('status', 'active')->pluck('revision')->unique()->all())->toBe([2])
        ->and($booking->fresh()->partnerAttributions()->where('partner_id', $a->id)->value('share_percentage'))->toBe('70.00');
});

it('clears attribution to a direct sale', function () {
    ['actor' => $actor, 'booking' => $booking, 'partners' => [$a]] = bookingAttributionWorld(1);

    app(SetBookingPartnerAttribution::class)->handle($booking, [
        ['partner_id' => $a->id, 'share_percentage' => '100', 'role' => 'primary'],
    ], $actor);
    app(RemoveBookingPartnerAttribution::class)->handle($booking->fresh(), $actor);

    expect($booking->fresh()->partnerAttributions()->count())->toBe(0)
        ->and(BookingPartnerAttribution::where('booking_id', $booking->id)->where('status', 'superseded')->count())->toBe(1)
        ->and($a->fresh()->activities()->where('type', PartnerActivityType::BookingAttributionRemoved->value)->exists())->toBeTrue();
});

it('refuses to attribute a cancelled booking', function () {
    ['actor' => $actor, 'booking' => $booking, 'partners' => [$a]] = bookingAttributionWorld(1);
    $booking->forceFill(['status' => 'cancelled', 'cancelled_at' => now()])->save();

    expect(fn () => app(SetBookingPartnerAttribution::class)->handle($booking->fresh(), [
        ['partner_id' => $a->id, 'share_percentage' => '100', 'role' => 'primary'],
    ], $actor))->toThrow(DomainException::class, 'cancelled');
});

it('drives the co-broker split from the booking partners screen', function () {
    ['booking' => $booking, 'partners' => [$a, $b]] = bookingAttributionWorld(2);
    $user = makeUser(permissions: ['bookings.view', 'partners.attribute']);

    Livewire::actingAs($user)
        ->test(BookingPartners::class, ['booking' => $booking])
        ->call('addRow')
        ->set('rows.0.partner_id', (string) $a->id)
        ->set('rows.0.share_percentage', '55')
        ->set('rows.0.role', 'primary')
        ->call('addRow')
        ->set('rows.1.partner_id', (string) $b->id)
        ->set('rows.1.share_percentage', '45')
        ->set('rows.1.role', 'co_broker')
        ->assertSet('total', '100.00')
        ->call('save')
        ->assertHasNoErrors();

    expect($booking->fresh()->partnerAttributions()->count())->toBe(2);
});

it('forbids the booking partners screen without partners.attribute', function () {
    ['booking' => $booking] = bookingAttributionWorld(0);
    $user = makeUser(permissions: ['bookings.view']);

    Livewire::actingAs($user)->test(BookingPartners::class, ['booking' => $booking])->assertForbidden();
});
