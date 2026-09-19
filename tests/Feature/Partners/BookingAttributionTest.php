<?php

declare(strict_types=1);

use App\Actions\Partners\AuthorizePartnerForProjectAction;
use App\Actions\Partners\RemoveBookingPartnerAttribution;
use App\Actions\Partners\SetBookingPartnerAttribution;
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
 * A confirmed booking + N active promoters already authorised for its project.
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

it('attributes a single promoter to a booking', function () {
    ['actor' => $actor, 'booking' => $booking, 'partners' => [$p]] = bookingAttributionWorld(1);

    app(SetBookingPartnerAttribution::class)->handle($booking, $p->id, $actor);

    $row = $booking->partnerAttributions()->sole();
    expect($row->partner_id)->toBe($p->id)
        ->and($p->fresh()->activities()->where('type', PartnerActivityType::BookingAttributed->value)->exists())->toBeTrue();
});

it('rejects a promoter not authorised for the project', function () {
    ['actor' => $actor, 'booking' => $booking] = bookingAttributionWorld(0);
    $unauthorised = Partner::factory()->active()->create();

    expect(fn () => app(SetBookingPartnerAttribution::class)->handle($booking, $unauthorised->id, $actor))
        ->toThrow(DomainException::class, 'not authorised');
});

it('rejects a non-active promoter', function () {
    ['actor' => $actor, 'booking' => $booking] = bookingAttributionWorld(0);
    $partner = Partner::factory()->status(PartnerStatus::OnHold)->create();
    app(AuthorizePartnerForProjectAction::class)->handle($partner, $booking->project, $actor);

    expect(fn () => app(SetBookingPartnerAttribution::class)->handle($booking, $partner->id, $actor))
        ->toThrow(DomainException::class);
});

it('rejects an unknown promoter id', function () {
    ['actor' => $actor, 'booking' => $booking] = bookingAttributionWorld(0);

    expect(fn () => app(SetBookingPartnerAttribution::class)->handle($booking, 999999, $actor))
        ->toThrow(DomainException::class, 'no longer exists');
});

it('supersedes the previous promoter and bumps the revision — history is kept', function () {
    ['actor' => $actor, 'booking' => $booking, 'partners' => [$a, $b]] = bookingAttributionWorld(2);

    app(SetBookingPartnerAttribution::class)->handle($booking, $a->id, $actor);
    app(SetBookingPartnerAttribution::class)->handle($booking->fresh(), $b->id, $actor);

    $all = BookingPartnerAttribution::where('booking_id', $booking->id)->get();
    expect($all)->toHaveCount(2)
        ->and($all->where('status', 'active')->count())->toBe(1)
        ->and($all->where('status', 'superseded')->count())->toBe(1)
        ->and($all->where('status', 'active')->first()->partner_id)->toBe($b->id)
        ->and($all->where('status', 'active')->first()->revision)->toBe(2);
});

it('is a no-op when re-setting the same promoter', function () {
    ['actor' => $actor, 'booking' => $booking, 'partners' => [$a]] = bookingAttributionWorld(1);

    app(SetBookingPartnerAttribution::class)->handle($booking, $a->id, $actor);
    app(SetBookingPartnerAttribution::class)->handle($booking->fresh(), $a->id, $actor);

    expect(BookingPartnerAttribution::where('booking_id', $booking->id)->count())->toBe(1);
});

it('clears attribution to a direct sale', function () {
    ['actor' => $actor, 'booking' => $booking, 'partners' => [$a]] = bookingAttributionWorld(1);

    app(SetBookingPartnerAttribution::class)->handle($booking, $a->id, $actor);
    app(RemoveBookingPartnerAttribution::class)->handle($booking->fresh(), $actor);

    expect($booking->fresh()->partnerAttributions()->count())->toBe(0)
        ->and(BookingPartnerAttribution::where('booking_id', $booking->id)->where('status', 'superseded')->count())->toBe(1)
        ->and($a->fresh()->activities()->where('type', PartnerActivityType::BookingAttributionRemoved->value)->exists())->toBeTrue();
});

it('refuses to attribute a cancelled booking', function () {
    ['actor' => $actor, 'booking' => $booking, 'partners' => [$a]] = bookingAttributionWorld(1);
    $booking->forceFill(['status' => 'cancelled', 'cancelled_at' => now()])->save();

    expect(fn () => app(SetBookingPartnerAttribution::class)->handle($booking->fresh(), $a->id, $actor))
        ->toThrow(DomainException::class, 'cancelled');
});

it('sets the promoter from the booking promoter screen', function () {
    ['booking' => $booking, 'partners' => [$a]] = bookingAttributionWorld(1);
    $user = makeUser(permissions: ['bookings.view', 'partners.attribute']);

    Livewire::actingAs($user)
        ->test(BookingPartners::class, ['booking' => $booking])
        ->set('partnerId', (string) $a->id)
        ->call('save')
        ->assertHasNoErrors();

    expect($booking->fresh()->partnerAttributions()->count())->toBe(1)
        ->and($booking->fresh()->partnerAttributions()->value('partner_id'))->toBe($a->id);
});

it('forbids the booking promoter screen without partners.attribute', function () {
    ['booking' => $booking] = bookingAttributionWorld(0);
    $user = makeUser(permissions: ['bookings.view']);

    Livewire::actingAs($user)->test(BookingPartners::class, ['booking' => $booking])->assertForbidden();
});
