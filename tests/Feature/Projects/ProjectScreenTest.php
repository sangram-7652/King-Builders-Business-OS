<?php

declare(strict_types=1);

use App\Enums\ProjectStatus;
use App\Models\Block;
use App\Models\Masters\City;
use App\Models\Masters\State;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(fn () => seedRbac());

it('renders the project list, create and edit screens', function () {
    $this->actingAs(projectManager());
    $project = Project::factory()->create(['name' => 'Screen Project']);

    $this->get(route('projects.index'))->assertOk()->assertSee('Screen Project');
    $this->get(route('projects.create'))->assertOk()->assertSee('New project');
    $this->get(route('projects.edit', $project))->assertOk()->assertSee('Edit project');
});

it('renders every tab on the project detail page', function () {
    $this->actingAs(projectManager());

    $state = State::factory()->create(['name' => 'Haryana']);
    $city = City::factory()->create(['state_id' => $state->id, 'name' => 'Gurugram']);
    $project = Project::factory()->status(ProjectStatus::Active)->create([
        'name' => 'Detail Project', 'state_id' => $state->id, 'city_id' => $city->id,
    ]);
    Block::factory()->create(['project_id' => $project->id, 'name' => 'Block A']);

    $this->get(route('projects.show', $project))->assertOk()
        ->assertSee('Detail Project')->assertSee('Active');
    $this->get(route('projects.show', ['project' => $project, 'tab' => 'location']))->assertOk()
        ->assertSee('Gurugram')->assertSee('Haryana');
    $this->get(route('projects.show', ['project' => $project, 'tab' => 'blocks']))->assertOk()
        ->assertSee('Block A');
    $this->get(route('projects.show', ['project' => $project, 'tab' => 'activity']))->assertOk();
});

it('shows Projects in the sidebar for a permitted user and hides it otherwise', function () {
    $this->actingAs(projectManager())->get(route('dashboard'))
        ->assertOk()->assertSee(route('projects.index'));

    $this->actingAs(makeUser())->get(route('dashboard'))
        ->assertOk()->assertDontSee(route('projects.index'));
});

it('does not show fake plot statistics on the detail page', function () {
    $this->actingAs(projectManager());
    $project = Project::factory()->create();

    $response = $this->get(route('projects.show', $project))->assertOk();
    $response->assertSee('Blocks');           // blocks are real in M3
    $response->assertDontSee('Total plots');  // plot inventory is M4 — no fabricated counts
    $response->assertDontSee('Sold');
});
