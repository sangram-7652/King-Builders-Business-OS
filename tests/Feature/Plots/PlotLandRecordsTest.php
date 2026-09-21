<?php

declare(strict_types=1);

use App\Enums\PlotStatus;
use App\Livewire\Plots\PlotForm;
use App\Models\Block;
use App\Models\Plot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(fn () => seedRbac());

it('saves village, Gata No. and the four Chauhaddi boundaries when creating a plot (66)', function () {
    $block = Block::factory()->create();

    Livewire::actingAs(plotManager())
        ->test(PlotForm::class, ['project' => $block->project, 'block' => $block])
        ->set('plot_number', '101')
        ->set('area', '1500')
        ->set('area_unit', 'sq_ft')
        ->set('village_name', 'Rampur Kalan')
        ->set('gata_number', 'GT-991')
        ->set('boundary_east', 'Plot 102')
        ->set('boundary_west', 'Main Road')
        ->set('boundary_north', 'Plot 100')
        ->set('boundary_south', 'Drain')
        ->call('save')
        ->assertHasNoErrors();

    $plot = Plot::firstWhere('plot_number', '101');
    expect($plot->village_name)->toBe('Rampur Kalan')
        ->and($plot->gata_number)->toBe('GT-991')
        ->and($plot->boundary_east)->toBe('Plot 102')
        ->and($plot->boundary_west)->toBe('Main Road')
        ->and($plot->boundary_north)->toBe('Plot 100')
        ->and($plot->boundary_south)->toBe('Drain');
});

it('leaves land records null when left blank — they never block a normal plot save (67)', function () {
    $block = Block::factory()->create();

    Livewire::actingAs(plotManager())
        ->test(PlotForm::class, ['project' => $block->project, 'block' => $block])
        ->set('plot_number', '202')
        ->set('area', '1200')
        ->set('area_unit', 'sq_ft')
        ->call('save')
        ->assertHasNoErrors();

    $plot = Plot::firstWhere('plot_number', '202');
    expect($plot->village_name)->toBeNull()
        ->and($plot->gata_number)->toBeNull()
        ->and($plot->boundary_east)->toBeNull();
});

it('updates land records on an existing plot without touching its status or hold (68)', function () {
    $block = Block::factory()->create();
    $plot = Plot::factory()->forBlock($block)->create(['plot_number' => '9']);

    Livewire::actingAs(plotManager())
        ->test(PlotForm::class, ['project' => $block->project, 'block' => $block, 'plot' => $plot])
        ->set('village_name', 'Naya Gaon')
        ->set('gata_number', 'GT-55')
        ->call('save')
        ->assertHasNoErrors();

    $plot->refresh();
    expect($plot->village_name)->toBe('Naya Gaon')
        ->and($plot->gata_number)->toBe('GT-55')
        ->and($plot->status)->toBe(PlotStatus::Available);
});
