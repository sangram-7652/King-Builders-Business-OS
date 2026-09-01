<?php

declare(strict_types=1);

use App\Actions\Collections\AssignCollectionCaseAction;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(fn () => seedRbac());

it('renders the collection dashboard, queue and case screens', function () {
    $s = overdueCaseScenario('1000000');
    $manager = collectionManager();

    $this->actingAs($manager);
    $this->get(route('collections.dashboard'))->assertOk()->assertSee('Collections dashboard')->assertSee('Overdue aging');
    $this->get(route('collections.queue'))->assertOk()->assertSee('Collection queue')->assertSee($s['booking']->booking_number);
    $this->get(route('collections.show', $s['case']))->assertOk()
        ->assertSee($s['booking']->booking_number)
        ->assertSee('Overdue installments')
        ->assertSee('Promises to pay');
    $this->get(route('collections.reports'))->assertOk()->assertSee('Collection reports');
});

it('renders the booking collection tab', function () {
    $s = overdueCaseScenario('1000000');

    $this->actingAs(collectionManager())
        ->get(route('collections.booking', $s['booking']))
        ->assertOk()
        ->assertSee('Collection —')
        ->assertSee('Installments');
});

it('shows a collection profile card on the buyer detail', function () {
    $s = overdueCaseScenario('1000000');
    $viewer = makeUser(permissions: ['buyers.view', 'collections.view']);

    $this->actingAs($viewer)
        ->get(route('buyers.show', $s['buyer']))
        ->assertOk()
        ->assertSee('Collection profile')
        ->assertSee('Total paid');
});

it('a scoped executive only sees their assigned case in the queue', function () {
    $s = overdueCaseScenario();
    $mine = collectionExecutive();
    $other = collectionExecutive();
    app(AssignCollectionCaseAction::class)->handle($s['case'], $mine, collectionManager());

    $this->actingAs($mine)->get(route('collections.queue'))->assertOk()->assertSee($s['booking']->booking_number);
    $this->actingAs($other)->get(route('collections.queue'))->assertOk()->assertDontSee($s['booking']->booking_number);
});

it('shows Collections in the sidebar only for a permitted user', function () {
    $this->actingAs(collectionExecutive())->get(route('dashboard'))->assertOk()->assertSee('Collections');
    $this->actingAs(makeUser())->get(route('dashboard'))->assertOk()->assertDontSee('>Collections<', false);
});
