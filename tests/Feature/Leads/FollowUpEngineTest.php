<?php

declare(strict_types=1);

use App\Actions\Leads\CancelFollowUp;
use App\Actions\Leads\CompleteFollowUp;
use App\Actions\Leads\MarkMissedFollowUps;
use App\Actions\Leads\RescheduleFollowUp;
use App\Actions\Leads\ScheduleFollowUp;
use App\Enums\FollowUpPriority;
use App\Enums\FollowUpStatus;
use App\Enums\FollowUpType;
use App\Enums\LeadActivityType;
use App\Exceptions\DomainException;
use App\Jobs\MarkMissedFollowUpsJob;
use App\Livewire\FollowUps\FollowUpQueue;
use App\Models\Lead;
use App\Models\LeadFollowUp;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    seedRbac();
    $this->travelTo(Carbon::parse('2026-09-15 09:00:00', 'UTC'));
});

function fuUser(array $perms = ['follow_ups.view', 'follow_ups.create', 'follow_ups.complete', 'follow_ups.update', 'leads.follow_up', 'leads.view']): User
{
    return makeUser(permissions: $perms);
}

// ---------------------------------------------------------------------------
//  Schedule
// ---------------------------------------------------------------------------

it('schedules a typed, prioritised follow-up and denormalises the next action', function () {
    $actor = fuUser();
    $lead = Lead::factory()->create(['assigned_to' => $actor->id]);

    $fu = app(ScheduleFollowUp::class)->handle($lead, [
        'due_at' => now()->addDay()->toDateTimeString(),
        'type' => 'meeting', 'priority' => 'high', 'title' => 'Show 3 options',
    ], $actor);

    expect($fu->type)->toBe(FollowUpType::Meeting)
        ->and($fu->priority)->toBe(FollowUpPriority::High)
        ->and($fu->status)->toBe(FollowUpStatus::Pending)
        ->and($fu->assigned_to)->toBe($actor->id)
        ->and($lead->fresh()->next_action_at->equalTo($fu->due_at))->toBeTrue()
        ->and($lead->fresh()->next_action)->toContain('Meeting')
        ->and($lead->fresh()->follow_up_at->equalTo($fu->due_at))->toBeTrue(); // M5 column kept in step
});

it('defaults the follow-up assignee to the lead owner', function () {
    $owner = User::factory()->create();
    $actor = fuUser();
    $lead = Lead::factory()->create(['assigned_to' => $owner->id]);

    $fu = app(ScheduleFollowUp::class)->handle($lead, ['due_at' => now()->addDay()->toDateTimeString()], $actor);

    expect($fu->assigned_to)->toBe($owner->id);
});

// ---------------------------------------------------------------------------
//  Complete
// ---------------------------------------------------------------------------

it('completes a follow-up, marks first contact, and re-syncs the next action', function () {
    $actor = fuUser();
    $lead = Lead::factory()->create(['assigned_to' => $actor->id, 'first_contacted_at' => null]);
    $a = app(ScheduleFollowUp::class)->handle($lead, ['due_at' => now()->addDay()->toDateTimeString()], $actor);
    $b = app(ScheduleFollowUp::class)->handle($lead, ['due_at' => now()->addDays(3)->toDateTimeString()], $actor);

    app(CompleteFollowUp::class)->handle($a, ['outcome' => 'connected'], $actor);

    expect($a->fresh()->status)->toBe(FollowUpStatus::Completed)
        ->and($a->fresh()->completed_by)->toBe($actor->id)
        ->and($lead->fresh()->first_contacted_at)->not->toBeNull()
        ->and($lead->fresh()->next_action_at->equalTo($b->due_at))->toBeTrue(); // now points at the other one
});

it('refuses to complete a non-open follow-up', function () {
    $actor = fuUser();
    $fu = LeadFollowUp::factory()->completed()->create();

    expect(fn () => app(CompleteFollowUp::class)->handle($fu, [], $actor))->toThrow(DomainException::class);
});

// ---------------------------------------------------------------------------
//  Reschedule — history preserved
// ---------------------------------------------------------------------------

it('reschedules by superseding: old row RESCHEDULED, new row links back', function () {
    $actor = fuUser();
    $lead = Lead::factory()->create(['assigned_to' => $actor->id]);
    $old = app(ScheduleFollowUp::class)->handle($lead, [
        'due_at' => now()->addDay()->toDateTimeString(), 'type' => 'call', 'priority' => 'urgent',
    ], $actor);

    $new = app(RescheduleFollowUp::class)->handle($old, ['due_at' => now()->addDays(5)->toDateTimeString()], $actor);

    expect($old->fresh()->status)->toBe(FollowUpStatus::Rescheduled)
        ->and($new->status)->toBe(FollowUpStatus::Pending)
        ->and($new->rescheduled_from_id)->toBe($old->id)
        ->and($new->type)->toBe(FollowUpType::Call)          // carried over
        ->and($new->priority)->toBe(FollowUpPriority::Urgent)
        ->and($new->rescheduledFrom->id)->toBe($old->id)
        ->and(LeadFollowUp::where('lead_id', $lead->id)->count())->toBe(2) // both rows kept
        ->and($lead->activities()->where('type', LeadActivityType::FollowUpRescheduled->value)->exists())->toBeTrue();
});

it('can reschedule a MISSED follow-up but not a completed one', function () {
    $actor = fuUser();
    $missed = LeadFollowUp::factory()->missed()->create();
    $done = LeadFollowUp::factory()->completed()->create();

    app(RescheduleFollowUp::class)->handle($missed, ['due_at' => now()->addDay()->toDateTimeString()], $actor);
    expect($missed->fresh()->status)->toBe(FollowUpStatus::Rescheduled);

    expect(fn () => app(RescheduleFollowUp::class)->handle($done, ['due_at' => now()->addDay()->toDateTimeString()], $actor))
        ->toThrow(DomainException::class);
});

// ---------------------------------------------------------------------------
//  Cancel
// ---------------------------------------------------------------------------

it('cancels a pending follow-up and clears the next action', function () {
    $actor = fuUser();
    $lead = Lead::factory()->create(['assigned_to' => $actor->id]);
    $fu = app(ScheduleFollowUp::class)->handle($lead, ['due_at' => now()->addDay()->toDateTimeString()], $actor);

    app(CancelFollowUp::class)->handle($fu, $actor, 'lead went cold');

    expect($fu->fresh()->status)->toBe(FollowUpStatus::Cancelled)
        ->and($fu->fresh()->cancelled_at)->not->toBeNull()
        ->and($lead->fresh()->next_action_at)->toBeNull();
});

// ---------------------------------------------------------------------------
//  Missed sweep — idempotent
// ---------------------------------------------------------------------------

it('flips overdue pending follow-ups to MISSED, once, via the sweep', function () {
    $lead = Lead::factory()->create();
    LeadFollowUp::factory()->create(['lead_id' => $lead->id, 'status' => 'pending', 'due_at' => now()->subHours(3)]);
    LeadFollowUp::factory()->create(['lead_id' => $lead->id, 'status' => 'pending', 'due_at' => now()->subMinutes(5)]); // inside grace
    LeadFollowUp::factory()->create(['lead_id' => $lead->id, 'status' => 'pending', 'due_at' => now()->addDay()]);

    $swept = app(MarkMissedFollowUps::class)->handle();
    expect($swept)->toBe(1);

    // second run does nothing (idempotent)
    expect(app(MarkMissedFollowUps::class)->handle())->toBe(0);

    expect(LeadFollowUp::where('status', 'missed')->count())->toBe(1)
        ->and($lead->activities()->where('type', LeadActivityType::FollowUpMissed->value)->count())->toBe(1);
});

it('runs the missed sweep as a unique queued job', function () {
    LeadFollowUp::factory()->create(['status' => 'pending', 'due_at' => now()->subHours(4)]);

    app(MarkMissedFollowUpsJob::class)->handle(app(MarkMissedFollowUps::class));

    expect(LeadFollowUp::where('status', 'missed')->count())->toBe(1)
        ->and((new MarkMissedFollowUpsJob) instanceof ShouldBeUnique)->toBeTrue();
});

// ---------------------------------------------------------------------------
//  Queue screen
// ---------------------------------------------------------------------------

it('renders the follow-up queue with the right tab counts', function () {
    $me = fuUser();
    $lead = Lead::factory()->create(['assigned_to' => $me->id]);

    LeadFollowUp::factory()->create(['lead_id' => $lead->id, 'assigned_to' => $me->id, 'status' => 'pending', 'due_at' => now()->setTime(15, 0)]);   // today
    LeadFollowUp::factory()->create(['lead_id' => $lead->id, 'assigned_to' => $me->id, 'status' => 'pending', 'due_at' => now()->subDays(2)]);       // overdue
    LeadFollowUp::factory()->create(['lead_id' => $lead->id, 'assigned_to' => $me->id, 'status' => 'pending', 'due_at' => now()->addDays(4)]);       // upcoming
    LeadFollowUp::factory()->missed()->create(['lead_id' => $lead->id, 'assigned_to' => $me->id]);                                                  // missed

    Livewire::actingAs($me)->test(FollowUpQueue::class)
        ->assertOk()
        ->assertSet('tab', 'today')
        ->assertViewHas('counts', fn ($c) => $c['today'] === 1 && $c['overdue'] === 1 && $c['upcoming'] === 1 && $c['missed'] === 1);
});

it('completes a follow-up from the queue', function () {
    $me = fuUser();
    $lead = Lead::factory()->create(['assigned_to' => $me->id]);
    $fu = LeadFollowUp::factory()->create(['lead_id' => $lead->id, 'assigned_to' => $me->id, 'status' => 'pending', 'due_at' => now()->setTime(15, 0)]);

    Livewire::actingAs($me)->test(FollowUpQueue::class)
        ->call('startComplete', $fu->id)
        ->set('completeOutcome', 'connected')
        ->call('complete');

    expect($fu->fresh()->status)->toBe(FollowUpStatus::Completed);
});

// ---------------------------------------------------------------------------
//  RBAC + scoping + IDOR
// ---------------------------------------------------------------------------

it('forbids the follow-up queue without follow_ups.view', function () {
    $this->actingAs(makeUser(permissions: ['leads.view']))->get('/follow-ups')->assertForbidden();
});

it('scopes the queue to the user\'s own follow-ups unless they hold leads.view_all', function () {
    $me = fuUser();
    $other = User::factory()->create();
    $mine = LeadFollowUp::factory()->create(['assigned_to' => $me->id, 'status' => 'pending', 'due_at' => now()->subDay()]);
    $theirs = LeadFollowUp::factory()->create(['assigned_to' => $other->id, 'status' => 'pending', 'due_at' => now()->subDay()]);

    Livewire::actingAs($me)->test(FollowUpQueue::class)->set('tab', 'overdue')
        ->assertSee($mine->lead->name)
        ->assertDontSee($theirs->lead->name);

    $manager = makeUser(permissions: ['follow_ups.view', 'leads.view_all', 'leads.view']);
    Livewire::actingAs($manager)->test(FollowUpQueue::class)->set('tab', 'overdue')
        ->assertSee($mine->lead->name)
        ->assertSee($theirs->lead->name);
});

it('a scoped user cannot complete another user\'s follow-up (IDOR)', function () {
    $me = fuUser();
    $others = LeadFollowUp::factory()->create([
        'assigned_to' => User::factory()->create()->id,
        'created_by' => User::factory()->create()->id,
        'status' => 'pending', 'due_at' => now()->subDay(),
    ]);

    Livewire::actingAs($me)->test(FollowUpQueue::class)
        ->call('startComplete', $others->id)
        ->call('complete')
        ->assertForbidden();

    expect($others->fresh()->status)->toBe(FollowUpStatus::Pending);
});
