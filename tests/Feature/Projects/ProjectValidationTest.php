<?php

declare(strict_types=1);

use App\Livewire\Projects\ProjectForm;
use App\Models\Masters\City;
use App\Models\Masters\State;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(fn () => seedRbac());

// --- Create form: current field contract --------------------------------

it('requires name and state on create', function () {
    Livewire::actingAs(projectManager())
        ->test(ProjectForm::class)
        ->set('name', '')
        ->set('state_id', '')
        ->call('save')
        ->assertHasErrors(['name', 'state_id']);
});

it('does not validate code on create — it is not part of the create form', function () {
    Livewire::actingAs(projectManager())
        ->test(ProjectForm::class)
        ->set('name', 'No Code Field')
        ->set('code', '')
        ->call('save')
        ->assertHasNoErrors('code');
});

it('rejects a city that does not belong to the selected state', function () {
    $stateA = State::factory()->create();
    $stateB = State::factory()->create();
    $cityInB = City::factory()->create(['state_id' => $stateB->id]);

    Livewire::actingAs(projectManager())
        ->test(ProjectForm::class)
        ->set('name', 'Mismatch')
        ->set('state_id', (string) $stateA->id)
        ->set('city_id', (string) $cityInB->id)
        ->call('save')
        ->assertHasErrors('city_id');

    expect(Project::where('name', 'Mismatch')->exists())->toBeFalse();
});

it('accepts a project with a state but no city', function () {
    $state = State::factory()->create();

    Livewire::actingAs(projectManager())
        ->test(ProjectForm::class)
        ->set('name', 'City optional')
        ->set('state_id', (string) $state->id)
        ->call('save')
        ->assertHasNoErrors();

    expect(Project::firstWhere('name', 'City optional')->city_id)->toBeNull();
});

// --- Create form: removed fields cannot be injected ----------------------

it('ignores code, latitude, longitude, contact and imagery fields injected into the create form', function () {
    $state = State::factory()->create();

    Livewire::actingAs(projectManager())
        ->test(ProjectForm::class)
        ->set('name', 'Injected Fields')
        ->set('state_id', (string) $state->id)
        ->set('code', 'INJECTED')
        ->set('latitude', '12.34')
        ->set('longitude', '56.78')
        ->set('contact_name', 'Sneaky Contact')
        ->set('contact_phone', '9999999999')
        ->set('contact_email', 'sneaky@example.com')
        ->set('logo_path', '/sneaky/logo.png')
        ->set('cover_image_path', '/sneaky/cover.png')
        ->call('save')
        ->assertHasNoErrors();

    $project = Project::firstWhere('name', 'Injected Fields');

    expect($project)->not->toBeNull()
        ->and($project->code)->not->toBe('INJECTED')
        ->and($project->latitude)->toBeNull()
        ->and($project->longitude)->toBeNull()
        ->and($project->contact_name)->toBeNull()
        ->and($project->contact_phone)->toBeNull()
        ->and($project->contact_email)->toBeNull()
        ->and($project->logo_path)->toBeNull()
        ->and($project->cover_image_path)->toBeNull();
});

// --- Edit form: code / latitude / longitude keep their existing rules ----

it('requires code when editing and rejects a duplicate', function () {
    Project::factory()->create(['code' => 'MK']);
    $project = Project::factory()->create(['code' => 'ON']);

    Livewire::actingAs(projectManager())
        ->test(ProjectForm::class, ['project' => $project])
        ->set('code', 'MK')
        ->call('save')
        ->assertHasErrors('code');
});

it('rejects a code with invalid characters when editing', function () {
    $project = Project::factory()->create(['code' => 'ON']);

    Livewire::actingAs(projectManager())
        ->test(ProjectForm::class, ['project' => $project])
        ->set('code', 'B K!')
        ->call('save')
        ->assertHasErrors('code');
});

it('validates latitude and longitude ranges when editing', function () {
    $project = Project::factory()->create();

    Livewire::actingAs(projectManager())
        ->test(ProjectForm::class, ['project' => $project])
        ->set('latitude', '120')
        ->set('longitude', '-500')
        ->call('save')
        ->assertHasErrors(['latitude', 'longitude']);
});
