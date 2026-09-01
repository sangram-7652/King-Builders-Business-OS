<?php

declare(strict_types=1);

use App\Livewire\Projects\ProjectBlocks;
use App\Models\Block;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(fn () => seedRbac());

it('adds a block to a project', function () {
    $project = Project::factory()->create();

    Livewire::actingAs(projectManager())
        ->test(ProjectBlocks::class, ['project' => $project])
        ->call('openCreate')
        ->set('name', 'Block A')
        ->set('code', 'A')
        ->set('description', 'North side')
        ->set('sort_order', 1)
        ->call('saveBlock')
        ->assertHasNoErrors();

    $block = Block::firstWhere('code', 'A');
    expect($block)->not->toBeNull()
        ->and($block->project_id)->toBe($project->id)
        ->and($block->name)->toBe('Block A');
});

it('resolves the project <-> blocks relationship both ways', function () {
    $project = Project::factory()->create();
    $block = Block::factory()->create(['project_id' => $project->id]);

    expect($project->blocks)->toHaveCount(1)
        ->and($project->blocks->first()->is($block))->toBeTrue()
        ->and($block->project->is($project))->toBeTrue();
});

it('rejects a duplicate block code within the same project', function () {
    $project = Project::factory()->create();
    Block::factory()->create(['project_id' => $project->id, 'code' => 'A']);

    Livewire::actingAs(projectManager())
        ->test(ProjectBlocks::class, ['project' => $project])
        ->call('openCreate')
        ->set('name', 'Block A dup')
        ->set('code', 'A')
        ->call('saveBlock')
        ->assertHasErrors('code');
});

it('allows the same block code in a different project', function () {
    $p1 = Project::factory()->create();
    $p2 = Project::factory()->create();
    Block::factory()->create(['project_id' => $p1->id, 'code' => 'A']);

    Livewire::actingAs(projectManager())
        ->test(ProjectBlocks::class, ['project' => $p2])
        ->call('openCreate')
        ->set('name', 'Block A')
        ->set('code', 'A')
        ->call('saveBlock')
        ->assertHasNoErrors();

    expect(Block::where('code', 'A')->count())->toBe(2);
});

it('edits a block', function () {
    $project = Project::factory()->create();
    $block = Block::factory()->create(['project_id' => $project->id, 'name' => 'Old', 'code' => 'A']);

    Livewire::actingAs(projectManager())
        ->test(ProjectBlocks::class, ['project' => $project])
        ->call('openEdit', $block->id)
        ->assertSet('name', 'Old')
        ->set('name', 'New name')
        ->call('saveBlock')
        ->assertHasNoErrors();

    expect($block->fresh()->name)->toBe('New name');
});

it('activates and deactivates a block', function () {
    $project = Project::factory()->create();
    $block = Block::factory()->create(['project_id' => $project->id, 'is_active' => true]);

    $component = Livewire::actingAs(projectManager())
        ->test(ProjectBlocks::class, ['project' => $project]);

    $component->call('toggleBlock', $block->id);
    expect($block->fresh()->is_active)->toBeFalse();

    $component->call('toggleBlock', $block->id);
    expect($block->fresh()->is_active)->toBeTrue();
});

it('soft-deletes a block', function () {
    $project = Project::factory()->create();
    $block = Block::factory()->create(['project_id' => $project->id]);

    Livewire::actingAs(projectManager())
        ->test(ProjectBlocks::class, ['project' => $project])
        ->call('deleteBlock', $block->id);

    expect(Block::find($block->id))->toBeNull()
        ->and(Block::withTrashed()->find($block->id))->not->toBeNull();
});

it('requires projects.update to manage blocks', function () {
    $viewer = makeUser(permissions: ['projects.view']);
    $project = Project::factory()->create();

    Livewire::actingAs($viewer)
        ->test(ProjectBlocks::class, ['project' => $project])
        ->call('openCreate')
        ->assertForbidden();
});

it('lets a project viewer see the blocks tab read-only', function () {
    $viewer = makeUser(permissions: ['projects.view']);
    $project = Project::factory()->create();
    Block::factory()->create(['project_id' => $project->id, 'name' => 'Block A']);

    Livewire::actingAs($viewer)
        ->test(ProjectBlocks::class, ['project' => $project])
        ->assertSee('Block A')
        ->assertDontSee('Add block');
});
