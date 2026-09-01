<?php

declare(strict_types=1);

use App\Actions\Collections\AssignCollectionCaseAction;
use App\Actions\Collections\CompleteCollectionFollowUpAction;
use App\Actions\Collections\ScheduleCollectionFollowUpAction;
use App\Enums\CollectionCaseStatus;
use App\Enums\CollectionFollowUpOutcome;
use App\Exceptions\DomainException;
use App\Models\CollectionCase;
use App\Models\CollectionFollowUp;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('creates a collection follow-up on a case (8)', function () {
    $s = overdueCaseScenario();

    $fu = app(ScheduleCollectionFollowUpAction::class)->handle($s['case'], [
        'follow_up_at' => now()->addDay()->toDateTimeString(),
        'notes' => 'Call the customer',
    ], $s['actor']);

    expect($fu->collection_case_id)->toBe($s['case']->id)
        ->and($fu->booking_id)->toBe($s['booking']->id)
        ->and($fu->completed_at)->toBeNull()
        ->and($s['case']->fresh()->next_follow_up_at)->not->toBeNull();
});

it('assigns a collection owner (9)', function () {
    $s = overdueCaseScenario();
    $owner = collectionExecutive();

    $case = app(AssignCollectionCaseAction::class)->handle($s['case'], $owner, collectionManager());

    expect($case->assigned_to)->toBe($owner->id)
        ->and($case->status)->toBe(CollectionCaseStatus::InProgress);
});

it('records a follow-up outcome (10)', function () {
    $s = overdueCaseScenario();
    $fu = app(ScheduleCollectionFollowUpAction::class)->handle($s['case'], ['follow_up_at' => now()->toDateTimeString()], $s['actor']);

    $done = app(CompleteCollectionFollowUpAction::class)->handle($fu, [
        'outcome' => CollectionFollowUpOutcome::Contacted->value,
        'notes' => 'Spoke to customer',
    ], $s['actor']);

    expect($done->outcome)->toBe(CollectionFollowUpOutcome::Contacted)
        ->and($done->completed_at)->not->toBeNull()
        ->and($s['case']->fresh()->status)->toBe(CollectionCaseStatus::InProgress);
});

it('schedules the next follow-up as a fresh row (11)', function () {
    $s = overdueCaseScenario();
    $fu = app(ScheduleCollectionFollowUpAction::class)->handle($s['case'], ['follow_up_at' => now()->toDateTimeString()], $s['actor']);

    app(CompleteCollectionFollowUpAction::class)->handle($fu, [
        'outcome' => CollectionFollowUpOutcome::CallBack->value,
        'next_follow_up_at' => now()->addDays(3)->toDateTimeString(),
    ], $s['actor']);

    expect(CollectionFollowUp::where('collection_case_id', $s['case']->id)->count())->toBe(2)
        ->and(CollectionFollowUp::where('collection_case_id', $s['case']->id)->whereNull('completed_at')->count())->toBe(1);
});

it('requires an outcome to complete a follow-up (12)', function () {
    $s = overdueCaseScenario();
    $fu = app(ScheduleCollectionFollowUpAction::class)->handle($s['case'], ['follow_up_at' => now()->toDateTimeString()], $s['actor']);

    expect(fn () => app(CompleteCollectionFollowUpAction::class)->handle($fu, ['outcome' => ''], $s['actor']))
        ->toThrow(DomainException::class);
});

it('restricts a scoped executive to their assigned cases (13)', function () {
    $s = overdueCaseScenario();
    $mine = collectionExecutive();
    $other = collectionExecutive();

    app(AssignCollectionCaseAction::class)->handle($s['case'], $mine, collectionManager());
    $case = $s['case']->fresh();

    expect($mine->can('view', $case))->toBeTrue()
        ->and($other->can('view', $case))->toBeFalse()
        ->and(collectionManager()->can('view', $case))->toBeTrue();

    // the scoped list query only returns own cases
    $visibleToOther = CollectionCase::query()->visibleTo($other)->pluck('id');
    expect($visibleToOther)->not->toContain($case->id);
});
