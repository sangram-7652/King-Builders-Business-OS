<?php

declare(strict_types=1);

use App\Actions\Projects\DeleteProject;
use App\Exceptions\DomainException;
use App\Livewire\Projects\ProjectShow;
use App\Models\Block;
use App\Models\Plot;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

/**
 * Coverage for the Delete action on the project detail page
 * (/projects/{project}). Project::businessDependents() only tracks plots —
 * blocks are intentionally cascade-soft-deleted with the project (see
 * DeleteProject) — so "dependent records" below means plots.
 */
uses(RefreshDatabase::class);

beforeEach(fn () => seedRbac());

it('shows the delete action on the detail page for an authorized user', function () {
    $project = Project::factory()->create();

    Livewire::actingAs(projectManager())
        ->test(ProjectShow::class, ['project' => $project])
        ->assertSee('wire:click="delete"', false);
});

it('hides the delete action on the detail page for a user without projects.delete', function () {
    $project = Project::factory()->create();
    $editor = makeUser(permissions: ['projects.view', 'projects.update']);

    Livewire::actingAs($editor)
        ->test(ProjectShow::class, ['project' => $project])
        ->assertDontSee('wire:click="delete"', false);
});

it('deletes an unblocked project from the detail page and redirects to the list', function () {
    $project = Project::factory()->create();

    Livewire::actingAs(projectManager())
        ->test(ProjectShow::class, ['project' => $project])
        ->call('delete')
        ->assertRedirect(route('projects.index'));

    expect(Project::find($project->id))->toBeNull()
        ->and(Project::withTrashed()->find($project->id))->not->toBeNull();
});

it('refuses to delete a project from the detail page without projects.delete', function () {
    $project = Project::factory()->create();
    $editor = makeUser(permissions: ['projects.view', 'projects.update']);

    Livewire::actingAs($editor)
        ->test(ProjectShow::class, ['project' => $project])
        ->call('delete')
        ->assertForbidden();

    expect(Project::find($project->id))->not->toBeNull();
});

it('protects a project with dependent plots from deletion', function () {
    $project = Project::factory()->create();
    $block = Block::factory()->for($project)->create();
    Plot::factory()->forBlock($block)->create();

    expect($project->hasBusinessDependents())->toBeTrue()
        ->and($project->blockingDependents())->toBe(['plots']);

    // The button itself is not rendered once the project has dependents.
    Livewire::actingAs(projectManager())
        ->test(ProjectShow::class, ['project' => $project])
        ->assertDontSee('wire:click="delete"', false)
        ->call('delete')
        ->assertForbidden();

    expect(Project::find($project->id))->not->toBeNull();
});

it('fails a blocked project deletion gracefully with a clear error, destroying nothing', function () {
    $project = Project::factory()->create();
    $block = Block::factory()->for($project)->create();
    Plot::factory()->forBlock($block)->create();

    expect(fn () => app(DeleteProject::class)->handle($project))
        ->toThrow(DomainException::class, 'This project has dependent records (plots) and cannot be deleted. Archive it instead.');

    expect(Project::find($project->id))->not->toBeNull()
        ->and(Block::find($block->id))->not->toBeNull();
});

it('leaves project status-change and archive actions working alongside delete', function () {
    $project = Project::factory()->create(['is_active' => true]);

    $component = Livewire::actingAs(projectManager())
        ->test(ProjectShow::class, ['project' => $project])
        ->assertSee('wire:click="delete"', false)
        ->call('toggleActive');

    expect($project->fresh()->is_active)->toBeFalse();

    $component->call('delete')->assertRedirect(route('projects.index'));
    expect(Project::withTrashed()->find($project->id)->trashed())->toBeTrue();
});
