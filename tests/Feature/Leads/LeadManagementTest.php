<?php

declare(strict_types=1);

use App\Actions\Leads\AssignLead;
use App\Actions\Leads\ChangeLeadStatus;
use App\Actions\Leads\CompleteFollowUp;
use App\Actions\Leads\ScheduleFollowUp;
use App\Enums\LeadActivityType;
use App\Enums\LeadStatus;
use App\Exceptions\DomainException;
use App\Livewire\Leads\LeadForm;
use App\Models\Lead;
use App\Models\LeadFollowUp;
use App\Models\Masters\LeadSource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(fn () => seedRbac());

it('creates a lead through the form and records a "created" activity', function () {
    $source = LeadSource::factory()->create();
    $manager = leadManager();

    Livewire::actingAs($manager)
        ->test(LeadForm::class)
        ->set('name', 'Ravi Kumar')
        ->set('phone', '98765 43210')
        ->set('email', 'ravi@example.com')
        ->set('lead_source_id', (string) $source->id)
        ->set('notes', 'Wants a 200 sq yd plot')
        ->call('save')
        ->assertHasNoErrors();

    $lead = Lead::firstWhere('name', 'Ravi Kumar');
    expect($lead)->not->toBeNull()
        ->and($lead->status)->toBe(LeadStatus::New)
        ->and($lead->created_by)->toBe($manager->id)
        ->and($lead->activities()->where('type', LeadActivityType::Created->value)->exists())->toBeTrue();
});

it('updates a lead but not a converted one', function () {
    $manager = leadManager();
    $lead = Lead::factory()->create(['name' => 'Old']);

    Livewire::actingAs($manager)
        ->test(LeadForm::class, ['lead' => $lead])
        ->set('name', 'New Name')
        ->call('save')
        ->assertHasNoErrors();
    expect($lead->fresh()->name)->toBe('New Name');

    $converted = Lead::factory()->status(LeadStatus::Converted)->create();
    Livewire::actingAs($manager)
        ->test(LeadForm::class, ['lead' => $converted])
        ->assertForbidden();
});

dataset('valid lead transitions', [
    'new → contacted' => [LeadStatus::New, LeadStatus::Contacted],
    'contacted → qualified' => [LeadStatus::Contacted, LeadStatus::Qualified],
    'follow_up → qualified' => [LeadStatus::FollowUp, LeadStatus::Qualified],
    'qualified → lost' => [LeadStatus::Qualified, LeadStatus::Lost],
    'lost → contacted' => [LeadStatus::Lost, LeadStatus::Contacted],
]);

dataset('invalid lead transitions', [
    'converted → new' => [LeadStatus::Converted, LeadStatus::New],
    'converted → qualified' => [LeadStatus::Converted, LeadStatus::Qualified],
    'new → qualified' => [LeadStatus::New, LeadStatus::Qualified],
    'new → converted' => [LeadStatus::New, LeadStatus::Converted],
    'qualified → converted (must use conversion)' => [LeadStatus::Qualified, LeadStatus::Converted],
]);

it('allows a valid status transition', function (LeadStatus $from, LeadStatus $to) {
    $lead = Lead::factory()->status($from)->create();

    app(ChangeLeadStatus::class)->handle($lead, $to, User::factory()->create());

    expect($lead->fresh()->status)->toBe($to);
})->with('valid lead transitions');

it('rejects an invalid status transition', function (LeadStatus $from, LeadStatus $to) {
    $lead = Lead::factory()->status($from)->create();

    expect(fn () => app(ChangeLeadStatus::class)->handle($lead, $to, User::factory()->create()))
        ->toThrow(DomainException::class);

    expect($lead->fresh()->status)->toBe($from);
})->with('invalid lead transitions');

it('resolves the lead source relationship', function () {
    $source = LeadSource::factory()->create(['name' => 'Referral']);
    $lead = Lead::factory()->create(['lead_source_id' => $source->id]);

    expect($lead->source->is($source))->toBeTrue()
        ->and($source->leads->first()->is($lead))->toBeTrue();
});

it('assigns a lead to an active user and logs the activity', function () {
    $agent = User::factory()->create();
    $lead = Lead::factory()->create();

    app(AssignLead::class)->handle($lead, $agent, leadManager());

    expect($lead->fresh()->assigned_to)->toBe($agent->id)
        ->and($lead->activities()->where('type', LeadActivityType::Assigned->value)->exists())->toBeTrue();
});

it('refuses to assign a lead to an inactive user', function () {
    $inactive = User::factory()->inactive()->create();
    $lead = Lead::factory()->create();

    expect(fn () => app(AssignLead::class)->handle($lead, $inactive, leadManager()))
        ->toThrow(DomainException::class);
});

it('schedules and completes a follow-up, keeping follow_up_at in sync', function () {
    $actor = User::factory()->create();
    $lead = Lead::factory()->create();

    $fu = app(ScheduleFollowUp::class)->handle($lead, [
        'due_at' => now()->addDays(2)->toDateTimeString(),
        'note' => 'Call back',
    ], $actor);

    $lead->refresh();
    expect($lead->follow_up_at)->not->toBeNull()
        ->and($lead->follow_up_at->toDateString())->toBe(now()->addDays(2)->toDateString())
        ->and($lead->activities()->where('type', LeadActivityType::FollowUpScheduled->value)->exists())->toBeTrue();

    app(CompleteFollowUp::class)->handle($fu, ['outcome' => 'connected', 'note' => 'Spoke, interested'], $actor);

    $lead->refresh();
    expect($fu->fresh()->completed_at)->not->toBeNull()
        ->and($lead->follow_up_at)->toBeNull()
        ->and($lead->activities()->where('type', LeadActivityType::FollowUpCompleted->value)->exists())->toBeTrue();
});

it('cannot complete a follow-up twice', function () {
    $fu = LeadFollowUp::factory()->completed()->create();

    expect(fn () => app(CompleteFollowUp::class)->handle($fu, ['outcome' => 'busy'], User::factory()->create()))
        ->toThrow(DomainException::class);
});
