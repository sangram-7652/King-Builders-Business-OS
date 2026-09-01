<?php

declare(strict_types=1);

use App\Models\Block;
use App\Models\Masters\PlotCategory;
use App\Models\Plot;
use App\Models\Project;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('creates the plots table with the expected columns and soft deletes', function () {
    expect(Schema::hasTable('plots'))->toBeTrue()
        ->and(Schema::hasColumns('plots', [
            'project_id', 'block_id', 'plot_number',
            'plot_category_id', 'plot_size_id', 'plot_dimension_id',
            'area', 'area_unit', 'facing', 'status', 'is_active',
            'held_at', 'hold_expires_at', 'hold_reason', 'held_by', 'deleted_at',
        ]))->toBeTrue();
});

it('enforces the unique (project_id, block_id, plot_number) constraint', function () {
    $block = Block::factory()->create();
    Plot::factory()->forBlock($block)->create(['plot_number' => '101']);

    expect(fn () => Plot::factory()->forBlock($block)->create(['plot_number' => '101']))
        ->toThrow(QueryException::class);
});

it('allows the same plot number in a different project', function () {
    $b1 = Block::factory()->create();
    $b2 = Block::factory()->create(); // different project

    Plot::factory()->forBlock($b1)->create(['plot_number' => '101']);
    Plot::factory()->forBlock($b2)->create(['plot_number' => '101']);

    expect(Plot::where('plot_number', '101')->count())->toBe(2);
});

it('nulls the master reference when a referenced master is force-deleted, keeping the area snapshot', function () {
    $category = PlotCategory::factory()->create();
    $plot = Plot::factory()->create(['plot_category_id' => $category->id, 'area' => 1200.50]);

    $category->forceDelete();

    $plot->refresh();
    expect($plot->plot_category_id)->toBeNull()
        ->and((float) $plot->area)->toBe(1200.50);
});

it('restricts hard-deleting a project or block that still has plots', function () {
    $block = Block::factory()->create();
    Plot::factory()->forBlock($block)->create();

    expect(fn () => $block->forceDelete())->toThrow(QueryException::class)
        ->and(fn () => $block->project->forceDelete())->toThrow(QueryException::class);
});

it('soft deletes a plot', function () {
    $plot = Plot::factory()->create();

    $plot->delete();

    expect(Plot::find($plot->id))->toBeNull()
        ->and(Plot::withTrashed()->find($plot->id))->not->toBeNull();
});
