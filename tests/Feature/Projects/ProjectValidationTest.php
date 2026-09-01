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

it('requires name, code and state', function () {
    Livewire::actingAs(projectManager())
        ->test(ProjectForm::class)
        ->set('name', '')
        ->set('code', '')
        ->set('state_id', '')
        ->call('save')
        ->assertHasErrors(['name', 'code', 'state_id']);
});

it('rejects a duplicate project code', function () {
    Project::factory()->create(['code' => 'MK']);
    $state = State::factory()->create();

    Livewire::actingAs(projectManager())
        ->test(ProjectForm::class)
        ->set('name', 'Another')
        ->set('code', 'MK')
        ->set('state_id', (string) $state->id)
        ->call('save')
        ->assertHasErrors('code');
});

it('rejects a code with invalid characters', function () {
    $state = State::factory()->create();

    Livewire::actingAs(projectManager())
        ->test(ProjectForm::class)
        ->set('name', 'Bad Code')
        ->set('code', 'M K!')
        ->set('state_id', (string) $state->id)
        ->call('save')
        ->assertHasErrors('code');
});

it('rejects a city that does not belong to the selected state', function () {
    $stateA = State::factory()->create();
    $stateB = State::factory()->create();
    $cityInB = City::factory()->create(['state_id' => $stateB->id]);

    Livewire::actingAs(projectManager())
        ->test(ProjectForm::class)
        ->set('name', 'Mismatch')
        ->set('code', 'MIS')
        ->set('state_id', (string) $stateA->id)
        ->set('city_id', (string) $cityInB->id)
        ->call('save')
        ->assertHasErrors('city_id');

    expect(Project::where('code', 'MIS')->exists())->toBeFalse();
});

it('accepts a project with a state but no city', function () {
    $state = State::factory()->create();

    Livewire::actingAs(projectManager())
        ->test(ProjectForm::class)
        ->set('name', 'City optional')
        ->set('code', 'CO')
        ->set('state_id', (string) $state->id)
        ->call('save')
        ->assertHasNoErrors();

    expect(Project::firstWhere('code', 'CO')->city_id)->toBeNull();
});

it('validates latitude and longitude ranges', function () {
    $state = State::factory()->create();

    Livewire::actingAs(projectManager())
        ->test(ProjectForm::class)
        ->set('name', 'Geo')
        ->set('code', 'GEO')
        ->set('state_id', (string) $state->id)
        ->set('latitude', '120')
        ->set('longitude', '-500')
        ->call('save')
        ->assertHasErrors(['latitude', 'longitude']);
});
