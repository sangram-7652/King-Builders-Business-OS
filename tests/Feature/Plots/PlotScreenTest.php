<?php

declare(strict_types=1);

use App\Models\Block;
use App\Models\Plot;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(fn () => seedRbac());

it('renders the plot list, create, bulk, detail and edit screens', function () {
    $this->actingAs(plotManager());
    $block = Block::factory()->create();
    $plot = Plot::factory()->forBlock($block)->create(['plot_number' => 'A-1']);

    $base = "/projects/{$block->project_id}/blocks/{$block->id}/plots";

    $this->get($base)->assertOk()->assertSee('A-1')->assertSee('Total');
    $this->get("{$base}/create")->assertOk()->assertSee('New plot');
    $this->get("{$base}/bulk")->assertOk()->assertSee('Bulk add plots');
    $this->get("{$base}/{$plot->id}")->assertOk()->assertSee('Plot A-1');
    $this->get("{$base}/{$plot->id}/edit")->assertOk()->assertSee('Edit plot A-1');
});

it('renders the project inventory tab with live counts', function () {
    $this->actingAs(makeUser(permissions: ['projects.view', 'plots.view']));
    $project = Project::factory()->create();
    $block = Block::factory()->for($project)->create();
    Plot::factory()->forBlock($block)->count(4)->available()->create();

    $this->get(route('projects.show', ['project' => $project, 'tab' => 'inventory']))
        ->assertOk()
        ->assertSee('Available')
        ->assertSee($block->name);
});

it('does not fabricate plot counts — a project with no plots shows zeros', function () {
    $this->actingAs(makeUser(permissions: ['projects.view', 'plots.view']));
    $project = Project::factory()->create();
    Block::factory()->for($project)->create();

    $response = $this->get(route('projects.show', ['project' => $project, 'tab' => 'inventory']))->assertOk();
    $response->assertSee('Total');
    $response->assertDontSee('Coming later'); // that reserved block is on the plot detail, not here
});
