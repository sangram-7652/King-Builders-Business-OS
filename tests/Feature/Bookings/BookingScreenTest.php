<?php

declare(strict_types=1);

use App\Actions\Bookings\ConfirmBookingAction;
use App\Actions\Bookings\CreateBookingAction;
use App\Actions\Bookings\SubmitBookingAction;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(fn () => seedRbac());

it('renders the booking list, create and edit screens', function () {
    $manager = bookingManager();
    $s = bookingScenario();
    $booking = app(CreateBookingAction::class)->handle(bookingPayload($s), $manager);

    $this->actingAs($manager);

    $this->get(route('bookings.index'))->assertOk()->assertSee('Bookings')->assertSee($booking->booking_number);
    $this->get(route('bookings.create'))->assertOk()->assertSee('New booking');
    $this->get(route('bookings.edit', $booking))->assertOk()->assertSee('Edit '.$booking->booking_number);
});

it('renders the booking detail with the pricing breakdown', function () {
    $manager = bookingManager();
    $s = bookingScenario();
    $booking = app(CreateBookingAction::class)->handle(bookingPayload($s), $manager);
    app(SubmitBookingAction::class)->handle($booking, $manager);
    $booking = app(ConfirmBookingAction::class)->handle($booking->fresh(), $manager);

    $this->actingAs($manager)
        ->get(route('bookings.show', $booking))
        ->assertOk()
        ->assertSee($booking->booking_number)
        ->assertSee('Pricing breakdown')
        ->assertSee('Final amount')
        ->assertSee(number_format((float) $booking->final_amount, 2));
});

it('shows the current booking on the plot detail screen', function () {
    $manager = makeUser(permissions: [
        'bookings.view', 'bookings.create', 'bookings.update', 'bookings.confirm',
        'plots.view', 'projects.view', 'buyers.view', 'pricing.view',
    ]);
    $s = bookingScenario();
    $booking = app(CreateBookingAction::class)->handle(bookingPayload($s), $manager);
    app(SubmitBookingAction::class)->handle($booking, $manager);
    app(ConfirmBookingAction::class)->handle($booking->fresh(), $manager);

    $this->actingAs($manager)
        ->get(route('plots.show', ['project' => $s['project']->id, 'block' => $s['block']->id, 'plot' => $s['plot']->id]))
        ->assertOk()
        ->assertSee('Current booking')
        ->assertSee($booking->booking_number);
});

it('shows Bookings in the sidebar for a permitted user and hides it otherwise', function () {
    $this->actingAs(bookingClerk())->get(route('dashboard'))->assertOk()->assertSee('Bookings');
    $this->actingAs(makeUser())->get(route('dashboard'))->assertOk()->assertDontSee('Bookings');
});
