<?php

declare(strict_types=1);

use App\Actions\Plots\CreatePlot;
use App\Enums\Masters\AreaUnit;
use App\Exceptions\DomainException;
use App\Models\Block;
use App\Models\Masters\PlotCategory;
use App\Models\Masters\PlotDimension;
use App\Models\Masters\PlotSize;
use App\Models\Plot;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('resolves every plot relationship', function () {
    $block = Block::factory()->create();
    $category = PlotCategory::factory()->create();
    $size = PlotSize::factory()->create();
    $dimension = PlotDimension::factory()->create();

    $plot = Plot::factory()->forBlock($block)->create([
        'plot_category_id' => $category->id,
        'plot_size_id' => $size->id,
        'plot_dimension_id' => $dimension->id,
    ]);

    expect($plot->project->is($block->project))->toBeTrue()
        ->and($plot->block->is($block))->toBeTrue()
        ->and($plot->category->is($category))->toBeTrue()
        ->and($plot->size->is($size))->toBeTrue()
        ->and($plot->dimension->is($dimension))->toBeTrue()
        ->and($block->plots->first()->is($plot))->toBeTrue()
        ->and($block->project->plots->first()->is($plot))->toBeTrue();
});

it('creates a plot and snapshots the area from the chosen size', function () {
    $block = Block::factory()->create();
    $size = PlotSize::factory()->create(['area' => 1776.05, 'unit' => AreaUnit::SquareFeet->value]);

    $plot = app(CreatePlot::class)->handle($block->project, $block, [
        'plot_number' => '  A-101  ',
        'plot_category_id' => null,
        'plot_size_id' => $size->id,
        'plot_dimension_id' => null,
        'area' => '',            // empty → snapshot from the size master
        'area_unit' => null,
        'facing' => 'north',
    ]);

    expect($plot->plot_number)->toBe('A-101')
        ->and((float) $plot->area)->toBe(1776.05)
        ->and($plot->area_unit)->toBe(AreaUnit::SquareFeet)
        ->and($plot->status->value)->toBe('available');

    // Later change to the master does not touch the plot.
    $size->update(['area' => 9999]);
    expect((float) $plot->fresh()->area)->toBe(1776.05);
});

it('rejects a plot whose block belongs to another project', function () {
    $projectA = Project::factory()->create();
    $blockB = Block::factory()->create(); // belongs to its own project

    expect(fn () => app(CreatePlot::class)->handle($projectA, $blockB, [
        'plot_number' => '1', 'plot_category_id' => null, 'plot_size_id' => null,
        'plot_dimension_id' => null, 'area' => 500, 'area_unit' => 'sq_ft', 'facing' => null,
    ]))->toThrow(DomainException::class, 'does not belong');
});

it('requires an area when no size is given', function () {
    $block = Block::factory()->create();

    expect(fn () => app(CreatePlot::class)->handle($block->project, $block, [
        'plot_number' => '1', 'plot_category_id' => null, 'plot_size_id' => null,
        'plot_dimension_id' => null, 'area' => '', 'area_unit' => null, 'facing' => null,
    ]))->toThrow(DomainException::class);
});
