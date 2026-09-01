<?php

declare(strict_types=1);

use App\Actions\Projects\ChangeProjectStatus;
use App\Enums\ProjectStatus;
use App\Exceptions\DomainException;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('exposes the five project statuses', function () {
    expect(ProjectStatus::values())
        ->toBe(['planning', 'active', 'on_hold', 'completed', 'closed']);
});

dataset('valid transitions', [
    'planning → active' => [ProjectStatus::Planning, ProjectStatus::Active],
    'planning → on_hold' => [ProjectStatus::Planning, ProjectStatus::OnHold],
    'active → on_hold' => [ProjectStatus::Active, ProjectStatus::OnHold],
    'active → completed' => [ProjectStatus::Active, ProjectStatus::Completed],
    'on_hold → active' => [ProjectStatus::OnHold, ProjectStatus::Active],
    'on_hold → closed' => [ProjectStatus::OnHold, ProjectStatus::Closed],
    'completed → closed' => [ProjectStatus::Completed, ProjectStatus::Closed],
]);

dataset('invalid transitions', [
    'planning → completed' => [ProjectStatus::Planning, ProjectStatus::Completed],
    'planning → closed' => [ProjectStatus::Planning, ProjectStatus::Closed],
    'active → planning' => [ProjectStatus::Active, ProjectStatus::Planning],
    'active → closed' => [ProjectStatus::Active, ProjectStatus::Closed],
    'completed → active' => [ProjectStatus::Completed, ProjectStatus::Active],
    'closed → active' => [ProjectStatus::Closed, ProjectStatus::Active],
    'closed → planning' => [ProjectStatus::Closed, ProjectStatus::Planning],
]);

it('allows a valid transition', function (ProjectStatus $from, ProjectStatus $to) {
    expect($from->canTransitionTo($to))->toBeTrue();

    $project = Project::factory()->status($from)->create();
    app(ChangeProjectStatus::class)->handle($project, $to);

    expect($project->fresh()->status)->toBe($to);
})->with('valid transitions');

it('rejects an invalid transition', function (ProjectStatus $from, ProjectStatus $to) {
    expect($from->canTransitionTo($to))->toBeFalse();

    $project = Project::factory()->status($from)->create();

    expect(fn () => app(ChangeProjectStatus::class)->handle($project, $to))
        ->toThrow(DomainException::class);

    expect($project->fresh()->status)->toBe($from);
})->with('invalid transitions');

it('treats a no-op transition to the same status as harmless', function () {
    $project = Project::factory()->status(ProjectStatus::Active)->create();

    app(ChangeProjectStatus::class)->handle($project, ProjectStatus::Active);

    expect($project->fresh()->status)->toBe(ProjectStatus::Active);
});

it('never auto-reopens a closed project', function () {
    expect(ProjectStatus::Closed->allowedTransitions())->toBe([])
        ->and(ProjectStatus::Closed->isTerminal())->toBeTrue();
});
