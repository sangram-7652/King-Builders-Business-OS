<?php

declare(strict_types=1);

use App\Livewire\Projects\ProjectForm;
use App\Livewire\Projects\ProjectIndex;
use App\Livewire\Projects\ProjectShow;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(fn () => seedRbac());

it('redirects a guest to login', function () {
    $this->get('/projects')->assertRedirect('/login');
});

it('forbids a user without projects.view', function () {
    $this->actingAs(makeUser())->get('/projects')->assertForbidden();
});

it('allows projects.view to list but not create', function () {
    $viewer = makeUser(permissions: ['projects.view']);

    $this->actingAs($viewer)->get('/projects')->assertOk();
    $this->actingAs($viewer)->get('/projects/create')->assertForbidden();
});

it('blocks the create component for a view-only user', function () {
    $viewer = makeUser(permissions: ['projects.view']);

    Livewire::actingAs($viewer)->test(ProjectForm::class)->assertForbidden();
});

it('blocks status changes without projects.update', function () {
    $viewer = makeUser(permissions: ['projects.view']);
    $project = Project::factory()->create();

    Livewire::actingAs($viewer)
        ->test(ProjectShow::class, ['project' => $project])
        ->call('changeStatus', 'active')
        ->assertForbidden();
});

it('separates activate and archive permissions', function () {
    $project = Project::factory()->create(['is_active' => true]);

    // Can archive, cannot re-activate.
    $archiver = makeUser(permissions: ['projects.view', 'projects.archive']);
    Livewire::actingAs($archiver)->test(ProjectIndex::class)->call('toggleActive', $project->id);
    expect($project->fresh()->is_active)->toBeFalse();

    Livewire::actingAs($archiver)->test(ProjectIndex::class)->call('toggleActive', $project->id)->assertForbidden();
    expect($project->fresh()->is_active)->toBeFalse();

    // The activator can turn it back on.
    $activator = makeUser(permissions: ['projects.view', 'projects.activate']);
    Livewire::actingAs($activator)->test(ProjectIndex::class)->call('toggleActive', $project->id);
    expect($project->fresh()->is_active)->toBeTrue();
});

it('blocks deletion without projects.delete', function () {
    $editor = makeUser(permissions: ['projects.view', 'projects.update']);
    $project = Project::factory()->create();

    Livewire::actingAs($editor)
        ->test(ProjectIndex::class)
        ->call('delete', $project->id)
        ->assertForbidden();

    expect(Project::find($project->id))->not->toBeNull();
});

it('lets a Super Admin reach every project screen', function () {
    $project = Project::factory()->create();
    $this->actingAs(superAdmin());

    $this->get(route('projects.index'))->assertOk();
    $this->get(route('projects.create'))->assertOk();
    $this->get(route('projects.show', $project))->assertOk();
    $this->get(route('projects.edit', $project))->assertOk();
});
