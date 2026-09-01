<?php

declare(strict_types=1);

use App\Actions\Bookings\CreateBookingAction;
use App\Actions\Bookings\OverrideBookingPriceAction;
use App\Actions\Bookings\SubmitBookingAction;
use App\Enums\Permission;
use App\Enums\PermissionGroup;
use App\Enums\PriceComponentType;
use App\Exceptions\DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(fn () => seedRbac());

it('gates the bookings list route on bookings.view', function () {
    $this->actingAs(makeUser())->get(route('bookings.index'))->assertForbidden();
    $this->actingAs(bookingClerk())->get(route('bookings.index'))->assertOk();
});

it('gates the create route on bookings.create', function () {
    $this->actingAs(makeUser(permissions: ['bookings.view']))->get(route('bookings.create'))->assertForbidden();
    $this->actingAs(bookingClerk())->get(route('bookings.create'))->assertOk();
});

it('lets a clerk build a booking but not confirm it', function () {
    $s = bookingScenario();
    $clerk = bookingClerk();

    $booking = app(CreateBookingAction::class)->handle(bookingPayload($s), $clerk);
    app(SubmitBookingAction::class)->handle($booking, $clerk);

    expect($clerk->can('confirm', $booking->fresh()))->toBeFalse()
        ->and(bookingManager()->can('confirm', $booking->fresh()))->toBeTrue();
});

it('only a pricing.override holder can override a price', function () {
    $s = bookingScenario();
    $clerk = bookingClerk();
    $booking = app(CreateBookingAction::class)->handle(bookingPayload($s), $clerk);

    expect(fn () => app(OverrideBookingPriceAction::class)->handle($booking, '1000000', 'discount', $clerk))
        ->toThrow(DomainException::class, 'not authorised');

    $manager = bookingManager();
    $result = app(OverrideBookingPriceAction::class)->handle($booking->fresh(), '1000000', 'board approved', $manager);

    expect($result->final_amount)->toBe('1000000.00')
        ->and($result->price_overridden)->toBeTrue()
        ->and($result->price_override_reason)->toBe('board approved')
        ->and($result->price_override_by)->toBe($manager->id);
});

it('rejects an override without a reason', function () {
    $s = bookingScenario();
    $manager = bookingManager();
    $booking = app(CreateBookingAction::class)->handle(bookingPayload($s), $manager);

    expect(fn () => app(OverrideBookingPriceAction::class)->handle($booking, '1000000', '   ', $manager))
        ->toThrow(DomainException::class, 'reason');
});

it('records an override as a labelled adjustment line, not a silent change', function () {
    $s = bookingScenario();
    $manager = bookingManager();
    // base 2,000,000 + 5% GST = 2,100,000
    $booking = app(CreateBookingAction::class)->handle(bookingPayload($s), $manager);

    $result = app(OverrideBookingPriceAction::class)->handle($booking, '2000000', 'waive taxes & round down', $manager);

    $overrideLine = $result->priceLines->firstWhere(fn ($l) => data_get($l->metadata, 'override') === true);
    expect($overrideLine)->not->toBeNull()
        ->and($overrideLine->name)->toBe('Manual price override')
        ->and($result->final_amount)->toBe('2000000.00')
        // the base line is untouched
        ->and($result->priceLines->firstWhere('type', PriceComponentType::Base)->amount)->toBe('2000000.00');
});

it('seeds the new booking + pricing permissions', function () {
    foreach (['bookings.confirm', 'bookings.delete', 'pricing.view', 'pricing.manage', 'pricing.override'] as $p) {
        expect(Spatie\Permission\Models\Permission::where('name', $p)->exists())->toBeTrue("missing {$p}");
    }

    expect(Permission::PricingOverride->group())->toBe(PermissionGroup::Finance);
});
