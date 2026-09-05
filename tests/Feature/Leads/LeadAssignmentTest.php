<?php

declare(strict_types=1);

use App\Actions\Leads\AssignLead;
use App\Actions\Leads\CreateLead;
use App\Enums\LeadActivityType;
use App\Exceptions\DomainException;
use App\Models\Lead;
use App\Models\LeadAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(fn () => seedRbac());

it('opens an assignment span when a lead is assigned', function () {
    $actor = User::factory()->create();
    $sp = User::factory()->create();
    $lead = Lead::factory()->create(['assigned_to' => null]);

    app(AssignLead::class)->handle($lead, $sp, $actor, 'walk-in enquiry');

    $span = LeadAssignment::where('lead_id', $lead->id)->sole();
    expect($span->assigned_to)->toBe($sp->id)
        ->and($span->assigned_by)->toBe($actor->id)
        ->and($span->ended_at)->toBeNull()
        ->and($span->reason)->toBe('walk-in enquiry')
        ->and($lead->fresh()->assigned_to)->toBe($sp->id)
        ->and($lead->fresh()->currentAssignment->assigned_to)->toBe($sp->id);
});

it('closes the old span and opens a new one on reassignment — history is never lost', function () {
    $actor = User::factory()->create();
    $spA = User::factory()->create();
    $spB = User::factory()->create();
    $lead = Lead::factory()->create(['assigned_to' => null]);

    app(AssignLead::class)->handle($lead, $spA, $actor);
    $this->travel(2)->days();
    app(AssignLead::class)->handle($lead->fresh(), $spB, $actor, 'territory change');

    $spans = LeadAssignment::where('lead_id', $lead->id)->orderBy('assigned_at')->get();
    expect($spans)->toHaveCount(2)
        ->and($spans[0]->assigned_to)->toBe($spA->id)
        ->and($spans[0]->ended_at)->not->toBeNull()   // closed
        ->and($spans[1]->assigned_to)->toBe($spB->id)
        ->and($spans[1]->ended_at)->toBeNull()        // current
        ->and(LeadAssignment::where('lead_id', $lead->id)->whereNull('ended_at')->count())->toBe(1);

    // the reassignment is on the activity timeline
    expect($lead->activities()->where('type', LeadActivityType::Reassigned->value)->exists())->toBeTrue();
});

it('closes the open span on unassignment and records no new span', function () {
    $actor = User::factory()->create();
    $sp = User::factory()->create();
    $lead = Lead::factory()->create(['assigned_to' => null]);

    app(AssignLead::class)->handle($lead, $sp, $actor);
    app(AssignLead::class)->handle($lead->fresh(), null, $actor, 'left the company');

    expect($lead->fresh()->assigned_to)->toBeNull()
        ->and($lead->fresh()->currentAssignment)->toBeNull()
        ->and(LeadAssignment::where('lead_id', $lead->id)->count())->toBe(1)
        ->and(LeadAssignment::where('lead_id', $lead->id)->whereNull('ended_at')->count())->toBe(0)
        ->and($lead->activities()->where('type', LeadActivityType::Unassigned->value)->exists())->toBeTrue();
});

it('records the assignment span when a lead is created already assigned', function () {
    $actor = User::factory()->create();
    $sp = User::factory()->create();

    $lead = app(CreateLead::class)->handle([
        'name' => 'Assigned On Create', 'phone' => '9000011122', 'email' => null,
        'lead_source_id' => null, 'assigned_to' => $sp->id, 'notes' => null,
    ], $actor);

    expect(LeadAssignment::where('lead_id', $lead->id)->whereNull('ended_at')->value('assigned_to'))->toBe($sp->id);
});

it('is a no-op when assigning to the current owner', function () {
    $actor = User::factory()->create();
    $sp = User::factory()->create();
    $lead = Lead::factory()->create(['assigned_to' => $sp->id]);
    LeadAssignment::create(['lead_id' => $lead->id, 'assigned_to' => $sp->id, 'assigned_by' => $actor->id, 'assigned_at' => now()]);

    app(AssignLead::class)->handle($lead, $sp, $actor);

    expect(LeadAssignment::where('lead_id', $lead->id)->count())->toBe(1);
});

it('refuses to assign a lead to an inactive user', function () {
    $lead = Lead::factory()->create();
    $inactive = User::factory()->create(['status' => 'inactive']);

    expect(fn () => app(AssignLead::class)->handle($lead, $inactive, User::factory()->create()))
        ->toThrow(DomainException::class);

    expect(LeadAssignment::where('lead_id', $lead->id)->count())->toBe(0);
});

it('lists unassigned leads via the scope', function () {
    Lead::factory()->count(2)->create(['assigned_to' => null]);
    Lead::factory()->create(['assigned_to' => User::factory()->create()->id]);

    expect(Lead::query()->unassigned()->count())->toBe(2);
});
