<?php

declare(strict_types=1);

use App\Enums\LeadActivityType;
use App\Models\Buyer;
use App\Models\Lead;
use App\Models\LeadFollowUp;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(fn () => seedRbac());

it('renders every lead screen', function () {
    $manager = leadManager();
    $this->actingAs($manager);
    $lead = Lead::factory()->create(['name' => 'Screen Lead', 'assigned_to' => $manager->id]);
    LeadFollowUp::factory()->for($lead)->create();
    $lead->recordActivity(LeadActivityType::Created, 'Lead created');

    $this->get(route('leads.index'))->assertOk()->assertSee('Screen Lead');
    $this->get(route('leads.create'))->assertOk()->assertSee('New lead');
    $this->get(route('leads.show', $lead))->assertOk()
        ->assertSee('Screen Lead')->assertSee('Activity')->assertSee('Follow-ups');
    $this->get(route('leads.edit', $lead))->assertOk()->assertSee('Edit lead');
});

it('renders the convert screen for a qualified lead', function () {
    $manager = leadManager();
    $lead = Lead::factory()->qualified()->create(['assigned_to' => $manager->id]);

    $this->actingAs($manager)->get(route('leads.convert', $lead))
        ->assertOk()->assertSee('Convert lead to buyer');
});

it('renders every buyer screen', function () {
    $this->actingAs(leadManager());
    $buyer = Buyer::factory()->create(['first_name' => 'Screen', 'middle_name' => null, 'last_name' => 'Buyer']);

    $this->get(route('buyers.index'))->assertOk()->assertSee('Screen Buyer');
    $this->get(route('buyers.create'))->assertOk()->assertSee('New buyer');
    $this->get(route('buyers.show', $buyer))->assertOk()->assertSee($buyer->customer_code);
    $this->get(route('buyers.edit', $buyer))->assertOk()->assertSee('Edit buyer');
});

it('shows Leads and Buyers in the sidebar for a permitted user and hides them otherwise', function () {
    $this->actingAs(leadManager())->get(route('dashboard'))
        ->assertOk()->assertSee(route('leads.index'))->assertSee(route('buyers.index'));

    $this->actingAs(makeUser())->get(route('dashboard'))
        ->assertOk()->assertDontSee(route('leads.index'))->assertDontSee(route('buyers.index'));
});
