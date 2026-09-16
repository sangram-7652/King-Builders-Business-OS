<?php

declare(strict_types=1);

use App\Actions\Plots\DeletePlot;
use App\Exceptions\DomainException;
use App\Livewire\Plots\PlotIndex;
use App\Models\Block;
use App\Models\Booking;
use App\Models\Plot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(fn () => seedRbac());

it('shows the delete action in the plot list for an authorized user', function () {
    $block = Block::factory()->create();
    $plot = Plot::factory()->forBlock($block)->available()->create(['plot_number' => 'A-1']);

    Livewire::actingAs(plotManager())
        ->test(PlotIndex::class, ['project' => $block->project, 'block' => $block])
        ->assertSee("wire:click=\"delete({$plot->id})\"", false);
});

it('hides the delete action in the plot list for a user without plots.delete', function () {
    $block = Block::factory()->create();
    $plot = Plot::factory()->forBlock($block)->available()->create();
    $editor = makeUser(permissions: ['plots.view', 'plots.update']);

    Livewire::actingAs($editor)
        ->test(PlotIndex::class, ['project' => $block->project, 'block' => $block])
        ->assertDontSee("wire:click=\"delete({$plot->id})\"", false);
});

it('deletes an unbooked plot from the list', function () {
    $block = Block::factory()->create();
    $plot = Plot::factory()->forBlock($block)->available()->create();

    Livewire::actingAs(plotManager())
        ->test(PlotIndex::class, ['project' => $block->project, 'block' => $block])
        ->call('delete', $plot->id);

    expect(Plot::find($plot->id))->toBeNull()
        ->and(Plot::withTrashed()->find($plot->id))->not->toBeNull();
});

it('refuses to delete a plot without plots.delete', function () {
    $block = Block::factory()->create();
    $plot = Plot::factory()->forBlock($block)->available()->create();
    $editor = makeUser(permissions: ['plots.view', 'plots.update']);

    Livewire::actingAs($editor)
        ->test(PlotIndex::class, ['project' => $block->project, 'block' => $block])
        ->call('delete', $plot->id)
        ->assertForbidden();

    expect(Plot::find($plot->id))->not->toBeNull();
});

it('protects a plot with a booking from deletion', function () {
    $block = Block::factory()->create();
    $plot = Plot::factory()->forBlock($block)->available()->create();
    Booking::factory()->forPlot($plot)->create();

    expect($plot->hasBusinessDependents())->toBeTrue()
        ->and($plot->blockingDependents())->toBe(['bookings']);

    Livewire::actingAs(plotManager())
        ->test(PlotIndex::class, ['project' => $block->project, 'block' => $block])
        ->assertDontSee("wire:click=\"delete({$plot->id})\"", false)
        ->call('delete', $plot->id)
        ->assertForbidden();

    expect(Plot::find($plot->id))->not->toBeNull();
});

it('fails a blocked plot deletion gracefully with a clear error, destroying nothing', function () {
    $block = Block::factory()->create();
    $plot = Plot::factory()->forBlock($block)->available()->create();
    Booking::factory()->forPlot($plot)->create();

    expect(fn () => app(DeletePlot::class)->handle($plot))
        ->toThrow(DomainException::class, 'This plot has dependent records (bookings) and cannot be deleted. Archive it instead.');

    expect(Plot::find($plot->id))->not->toBeNull();
});

it('refreshes the plot list after a successful deletion', function () {
    $block = Block::factory()->create();
    $plot = Plot::factory()->forBlock($block)->available()->create(['plot_number' => 'A-1']);

    Livewire::actingAs(plotManager())
        ->test(PlotIndex::class, ['project' => $block->project, 'block' => $block])
        ->assertSee('A-1')
        ->call('delete', $plot->id)
        ->assertDontSee('A-1');
});

it('leaves hold and edit actions working alongside delete', function () {
    $block = Block::factory()->create();
    $plot = Plot::factory()->forBlock($block)->available()->create(['plot_number' => 'A-1']);

    Livewire::actingAs(plotManager())
        ->test(PlotIndex::class, ['project' => $block->project, 'block' => $block])
        ->assertSee("wire:click=\"startHold({$plot->id})\"", false)
        ->assertSee("wire:click=\"delete({$plot->id})\"", false)
        ->call('startHold', $plot->id)
        ->set('holdReason', 'Buyer requested')
        ->call('confirmHold');

    expect($plot->fresh()->status->value)->toBe('hold');
});
