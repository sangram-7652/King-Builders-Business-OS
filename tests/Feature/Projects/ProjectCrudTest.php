<?php

declare(strict_types=1);

use App\Enums\ProjectStatus;
use App\Livewire\Projects\ProjectForm;
use App\Livewire\Projects\ProjectIndex;
use App\Livewire\Projects\ProjectShow;
use App\Models\Masters\City;
use App\Models\Masters\State;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(fn () => seedRbac());

it('creates a project (starts in Planning, gets a slug)', function () {
    $state = State::factory()->create();
    $city = City::factory()->create(['state_id' => $state->id]);

    Livewire::actingAs(projectManager())
        ->test(ProjectForm::class)
        ->set('name', 'Madhav Kunj')
        ->set('code', 'MK')
        ->set('description', 'Township on NH-9')
        ->set('state_id', (string) $state->id)
        ->set('city_id', (string) $city->id)
        ->set('address', 'NH-9, Ghaziabad')
        ->set('pincode', '201001')
        ->call('save')
        ->assertHasNoErrors();

    $project = Project::firstWhere('code', 'MK');

    expect($project)->not->toBeNull()
        ->and($project->slug)->toBe('madhav-kunj')
        ->and($project->status)->toBe(ProjectStatus::Planning)
        ->and($project->is_active)->toBeTrue()
        ->and($project->state_id)->toBe($state->id)
        ->and($project->city_id)->toBe($city->id);
});

it('updates a project without changing its slug or status', function () {
    $project = Project::factory()->status(ProjectStatus::Active)->create([
        'name' => 'Old Name', 'slug' => 'old-name', 'code' => 'ON',
    ]);
    $state = State::factory()->create();

    Livewire::actingAs(projectManager())
        ->test(ProjectForm::class, ['project' => $project])
        ->set('name', 'New Name')
        ->set('state_id', (string) $state->id)
        ->call('save')
        ->assertHasNoErrors();

    $project->refresh();
    expect($project->name)->toBe('New Name')
        ->and($project->slug)->toBe('old-name')
        ->and($project->status)->toBe(ProjectStatus::Active);
});

it('archives and re-activates a project from the list', function () {
    $project = Project::factory()->create(['is_active' => true]);

    $component = Livewire::actingAs(projectManager())->test(ProjectIndex::class);

    $component->call('toggleActive', $project->id);
    expect($project->fresh()->is_active)->toBeFalse();

    $component->call('toggleActive', $project->id);
    expect($project->fresh()->is_active)->toBeTrue();
});

it('changes status from the detail page along a valid path', function () {
    $project = Project::factory()->status(ProjectStatus::Planning)->create();

    Livewire::actingAs(projectManager())
        ->test(ProjectShow::class, ['project' => $project])
        ->call('changeStatus', 'active');

    expect($project->fresh()->status)->toBe(ProjectStatus::Active);
});

it('refuses an invalid status change from the detail page (no state change)', function () {
    $project = Project::factory()->status(ProjectStatus::Planning)->create();

    Livewire::actingAs(projectManager())
        ->test(ProjectShow::class, ['project' => $project])
        ->call('changeStatus', 'closed');

    expect($project->fresh()->status)->toBe(ProjectStatus::Planning);
});

it('searches, filters and sorts the project list', function () {
    $hr = State::factory()->create(['name' => 'Haryana']);
    $up = State::factory()->create(['name' => 'Uttar Pradesh']);

    Project::factory()->create(['name' => 'Alpha Greens', 'code' => 'AG', 'state_id' => $hr->id, 'status' => ProjectStatus::Active]);
    Project::factory()->create(['name' => 'Beta Enclave', 'code' => 'BE', 'state_id' => $up->id, 'status' => ProjectStatus::Planning]);

    Livewire::actingAs(projectManager())
        ->test(ProjectIndex::class)
        ->set('search', 'alpha')->assertSee('Alpha Greens')->assertDontSee('Beta Enclave')
        ->set('search', '')
        ->set('state', (string) $up->id)->assertSee('Beta Enclave')->assertDontSee('Alpha Greens')
        ->set('state', '')
        ->set('status', 'active')->assertSee('Alpha Greens')->assertDontSee('Beta Enclave');
});

it('deletes a project (soft) from the list', function () {
    $project = Project::factory()->create();

    Livewire::actingAs(projectManager())
        ->test(ProjectIndex::class)
        ->call('delete', $project->id);

    expect(Project::find($project->id))->toBeNull()
        ->and(Project::withTrashed()->find($project->id))->not->toBeNull();
});
