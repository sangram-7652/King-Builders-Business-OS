<?php

declare(strict_types=1);

use App\Actions\Projects\DeleteProject;
use App\Models\Block;
use App\Models\Masters\State;
use App\Models\Project;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('creates the projects and blocks tables with soft deletes', function () {
    expect(Schema::hasTable('projects'))->toBeTrue()
        ->and(Schema::hasTable('blocks'))->toBeTrue()
        ->and(Schema::hasColumns('projects', ['code', 'slug', 'status', 'is_active', 'state_id', 'city_id', 'deleted_at']))->toBeTrue()
        ->and(Schema::hasColumns('blocks', ['project_id', 'code', 'sort_order', 'is_active', 'deleted_at']))->toBeTrue();
});

it('enforces a unique project code', function () {
    Project::factory()->create(['code' => 'MK']);

    expect(fn () => Project::factory()->create(['code' => 'MK']))
        ->toThrow(QueryException::class);
});

it('enforces a unique project slug', function () {
    Project::factory()->create(['slug' => 'madhav-kunj']);

    expect(fn () => Project::factory()->create(['slug' => 'madhav-kunj']))
        ->toThrow(QueryException::class);
});

it('generates a unique slug from the name', function () {
    $a = Project::factory()->create(['name' => 'Madhav Kunj', 'slug' => Project::generateSlug('Madhav Kunj')]);
    $b = Project::factory()->create(['name' => 'Madhav Kunj', 'slug' => Project::generateSlug('Madhav Kunj')]);

    expect($a->slug)->toBe('madhav-kunj')
        ->and($b->slug)->toBe('madhav-kunj-2');
});

it('nulls the project state/city when a referenced master is force-deleted', function () {
    $state = State::factory()->create();
    $project = Project::factory()->create(['state_id' => $state->id]);

    $state->forceDelete();

    expect($project->fresh()->state_id)->toBeNull();
});

it('enforces the composite unique (project_id, code) on blocks', function () {
    $project = Project::factory()->create();
    Block::factory()->create(['project_id' => $project->id, 'code' => 'A']);

    expect(fn () => Block::factory()->create(['project_id' => $project->id, 'code' => 'A']))
        ->toThrow(QueryException::class);
});

it('allows the same block code in a different project', function () {
    $p1 = Project::factory()->create();
    $p2 = Project::factory()->create();

    Block::factory()->create(['project_id' => $p1->id, 'code' => 'A']);
    Block::factory()->create(['project_id' => $p2->id, 'code' => 'A']);

    expect(Block::where('code', 'A')->count())->toBe(2);
});

it('soft deletes a project and cascades a soft delete to its blocks', function () {
    $project = Project::factory()->create();
    Block::factory()->count(2)->create(['project_id' => $project->id]);

    app(DeleteProject::class)->handle($project);

    expect(Project::find($project->id))->toBeNull()
        ->and(Project::withTrashed()->find($project->id))->not->toBeNull()
        ->and(Block::where('project_id', $project->id)->count())->toBe(0)
        ->and(Block::withTrashed()->where('project_id', $project->id)->count())->toBe(2);
});
