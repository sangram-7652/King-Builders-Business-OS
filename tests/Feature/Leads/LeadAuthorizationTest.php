<?php

declare(strict_types=1);

use App\Enums\LeadStatus;
use App\Livewire\Leads\LeadIndex;
use App\Livewire\Leads\LeadShow;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(fn () => seedRbac());

it('redirects a guest and forbids a user without leads.view', function () {
    $this->get('/leads')->assertRedirect('/login');
    $this->actingAs(makeUser())->get('/leads')->assertForbidden();
});

it('scopes the list — an agent sees only their own or assigned leads', function () {
    $agent = leadAgent();
    $other = User::factory()->create();

    $mine = Lead::factory()->create(['assigned_to' => $agent->id]);
    $createdByMe = Lead::factory()->create(['created_by' => $agent->id]);
    $theirs = Lead::factory()->create(['assigned_to' => $other->id]);

    Livewire::actingAs($agent)
        ->test(LeadIndex::class)
        ->assertSee($mine->name)
        ->assertSee($createdByMe->name)
        ->assertDontSee($theirs->name);
});

it('a manager with leads.view_all sees every lead', function () {
    $manager = leadManager();
    $theirs = Lead::factory()->create(['assigned_to' => User::factory()->create()->id]);

    Livewire::actingAs($manager)->test(LeadIndex::class)->assertSee($theirs->name);
});

it('forbids viewing a lead you have no scope for', function () {
    $agent = leadAgent();
    $lead = Lead::factory()->create(['assigned_to' => User::factory()->create()->id]);

    $this->actingAs($agent)->get(route('leads.show', $lead))->assertForbidden();

    Livewire::actingAs($agent)->test(LeadShow::class, ['lead' => $lead])->assertForbidden();
});

it('requires leads.assign to assign', function () {
    $agent = leadAgent(); // no leads.assign
    $lead = Lead::factory()->create(['assigned_to' => $agent->id]);

    Livewire::actingAs($agent)
        ->test(LeadShow::class, ['lead' => $lead])
        ->call('openAssign')
        ->assertForbidden();
});

it('requires leads.follow_up to schedule a follow-up', function () {
    $user = makeUser(permissions: ['leads.view']);
    $lead = Lead::factory()->create(['created_by' => $user->id]);

    Livewire::actingAs($user)
        ->test(LeadShow::class, ['lead' => $lead])
        ->call('openFollowUp')
        ->assertForbidden();
});

it('cannot delete a converted lead', function () {
    $manager = leadManager();
    $lead = Lead::factory()->status(LeadStatus::Converted)->create();

    Livewire::actingAs($manager)
        ->test(LeadIndex::class)
        ->call('delete', $lead->id)
        ->assertForbidden();

    expect(Lead::find($lead->id))->not->toBeNull();
});
