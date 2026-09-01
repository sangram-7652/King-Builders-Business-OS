<?php

declare(strict_types=1);

use App\Enums\PlotStatus;
use App\Livewire\Plots\PlotForm;
use App\Livewire\Plots\PlotIndex;
use App\Livewire\Plots\PlotShow;
use App\Livewire\Plots\ProjectInventory;
use App\Models\Block;
use App\Models\Masters\PlotSize;
use App\Models\Plot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(fn () => seedRbac());

it('creates a plot through the form', function () {
    $block = Block::factory()->create();
    $size = PlotSize::factory()->create(['area' => 1500]);

    Livewire::actingAs(plotManager())
        ->test(PlotForm::class, ['project' => $block->project, 'block' => $block])
        ->set('plot_number', '101')
        ->set('plot_size_id', (string) $size->id)
        ->set('area', '1500')
        ->set('area_unit', 'sq_ft')
        ->set('facing', 'north')
        ->call('save')
        ->assertHasNoErrors();

    $plot = Plot::firstWhere('plot_number', '101');
    expect($plot)->not->toBeNull()
        ->and($plot->block_id)->toBe($block->id)
        ->and($plot->project_id)->toBe($block->project_id)
        ->and($plot->status)->toBe(PlotStatus::Available);
});

it('updates a plot without changing its status or hold', function () {
    $block = Block::factory()->create();
    $plot = Plot::factory()->forBlock($block)->onHold(now()->addDay())->create(['plot_number' => '9']);

    Livewire::actingAs(plotManager())
        ->test(PlotForm::class, ['project' => $block->project, 'block' => $block, 'plot' => $plot])
        ->set('plot_number', '9A')
        ->set('area', '2000')
        ->call('save')
        ->assertHasNoErrors();

    $plot->refresh();
    expect($plot->plot_number)->toBe('9A')
        ->and((float) $plot->area)->toBe(2000.0)
        ->and($plot->status)->toBe(PlotStatus::Hold)
        ->and($plot->held_at)->not->toBeNull();
});

it('rejects a duplicate plot number within the block', function () {
    $block = Block::factory()->create();
    Plot::factory()->forBlock($block)->create(['plot_number' => '101']);

    Livewire::actingAs(plotManager())
        ->test(PlotForm::class, ['project' => $block->project, 'block' => $block])
        ->set('plot_number', '101')
        ->set('area', '1000')->set('area_unit', 'sq_ft')
        ->call('save')
        ->assertHasErrors('plot_number');
});

it('holds and releases a plot from the list', function () {
    $block = Block::factory()->create();
    $plot = Plot::factory()->forBlock($block)->available()->create();

    $user = plotManager();
    $component = Livewire::actingAs($user)->test(PlotIndex::class, ['project' => $block->project, 'block' => $block]);

    $component->call('startHold', $plot->id)
        ->set('holdReason', 'Walk-in customer')
        ->call('confirmHold');

    $plot->refresh();
    expect($plot->status)->toBe(PlotStatus::Hold)
        ->and($plot->held_by)->toBe($user->id);

    $component->call('release', $plot->id);
    expect($plot->fresh()->status)->toBe(PlotStatus::Available);
});

it('changes status along the lifecycle from the detail page', function () {
    $block = Block::factory()->create();
    $plot = Plot::factory()->forBlock($block)->status(PlotStatus::Hold)->create();

    Livewire::actingAs(plotManager())
        ->test(PlotShow::class, ['project' => $block->project, 'block' => $block, 'plot' => $plot])
        ->call('changeStatus', 'booked');

    expect($plot->fresh()->status)->toBe(PlotStatus::Booked);
});

it('refuses an invalid status change from the detail page', function () {
    $block = Block::factory()->create();
    $plot = Plot::factory()->forBlock($block)->available()->create();

    Livewire::actingAs(plotManager())
        ->test(PlotShow::class, ['project' => $block->project, 'block' => $block, 'plot' => $plot])
        ->call('changeStatus', 'sold');

    expect($plot->fresh()->status)->toBe(PlotStatus::Available);
});

it('searches and filters the plot list', function () {
    $block = Block::factory()->create();
    Plot::factory()->forBlock($block)->create(['plot_number' => '101', 'status' => PlotStatus::Available->value]);
    Plot::factory()->forBlock($block)->create(['plot_number' => '202', 'status' => PlotStatus::Booked->value]);

    Livewire::actingAs(plotManager())
        ->test(PlotIndex::class, ['project' => $block->project, 'block' => $block])
        ->set('search', '101')->assertSee('101')->assertDontSee('202')
        ->set('search', '')
        ->set('status', 'booked')->assertSee('202')->assertDontSee('101');
});

it('archives an available plot but not one mid-lifecycle', function () {
    $block = Block::factory()->create();
    $available = Plot::factory()->forBlock($block)->available()->create();
    $held = Plot::factory()->forBlock($block)->status(PlotStatus::Hold)->create();

    $component = Livewire::actingAs(plotManager())->test(PlotIndex::class, ['project' => $block->project, 'block' => $block]);

    $component->call('toggleActive', $available->id);
    expect($available->fresh()->is_active)->toBeFalse();

    $component->call('toggleActive', $held->id);
    expect($held->fresh()->is_active)->toBeTrue(); // rejected — still on hold
});

it('shows live inventory counts on the project tab', function () {
    $block = Block::factory()->create();
    Plot::factory()->forBlock($block)->count(3)->available()->create();
    Plot::factory()->forBlock($block)->count(2)->status(PlotStatus::Booked)->create();

    Livewire::actingAs(makeUser(permissions: ['projects.view', 'plots.view']))
        ->test(ProjectInventory::class, ['project' => $block->project])
        ->assertSeeInOrder(['Total', '5'])
        ->assertSee('Available')
        ->assertSee('Booked');
});
