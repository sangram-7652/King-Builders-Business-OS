<?php

declare(strict_types=1);

use App\Enums\PlotStatus;
use App\Livewire\Bookings\BookingForm;
use App\Models\Block;
use App\Models\Booking;
use App\Models\Buyer;
use App\Models\Plot;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(fn () => seedRbac());

/**
 * Project "Madhav Kunj 2" with Block A (A1, A2), Block B (B1) and direct
 * plots 100, 102 — plus an unrelated project whose plots must never leak in.
 *
 * @return array<string, mixed>
 */
function plotFilterWorld(): array
{
    // An unrelated project/block first, so real block ids never line up with
    // 0-based option indexes by coincidence.
    $other = Project::factory()->create();
    $otherBlock = Block::factory()->create(['project_id' => $other->id]);
    $otherPlot = Plot::factory()->forBlock($otherBlock)->create(['plot_number' => 'X9', 'status' => PlotStatus::Available->value]);

    $project = Project::factory()->create(['name' => 'Madhav Kunj 2']);
    $blockA = Block::factory()->create(['project_id' => $project->id, 'name' => 'Block A']);
    $blockB = Block::factory()->create(['project_id' => $project->id, 'name' => 'Block B']);
    $available = ['status' => PlotStatus::Available->value];

    return [
        'project' => $project,
        'blockA' => $blockA,
        'blockB' => $blockB,
        'a1' => Plot::factory()->forBlock($blockA)->create(['plot_number' => 'A1'] + $available),
        'a2' => Plot::factory()->forBlock($blockA)->create(['plot_number' => 'A2'] + $available),
        'b1' => Plot::factory()->forBlock($blockB)->create(['plot_number' => 'B1'] + $available),
        'd100' => Plot::factory()->direct()->create(['project_id' => $project->id, 'plot_number' => '100'] + $available),
        'd102' => Plot::factory()->direct()->create(['project_id' => $project->id, 'plot_number' => '102'] + $available),
        'otherPlot' => $otherPlot,
    ];
}

/** @return list<int> the plot ids currently offered by the Plot dropdown */
function offeredPlotIds(Testable $component): array
{
    return collect($component->viewData('plots'))->keys()->map(fn ($id) => (int) $id)->sort()->values()->all();
}

function sortedIds(Plot ...$plots): array
{
    return collect($plots)->pluck('id')->sort()->values()->all();
}

it('offers the real Block ids as Block option values (root cause)', function () {
    $w = plotFilterWorld();

    $blocks = Livewire::actingAs(bookingManager())
        ->test(BookingForm::class)
        ->set('project_id', (string) $w['project']->id)
        ->viewData('blocks');

    expect($blocks->keys()->all())->toBe([BookingForm::DIRECT_BLOCK, $w['blockA']->id, $w['blockB']->id]);
});

it('Project + Block A shows only Block A plots, never direct plots (1, 4)', function () {
    $w = plotFilterWorld();

    $c = Livewire::actingAs(bookingManager())->test(BookingForm::class)
        ->set('project_id', (string) $w['project']->id)
        ->set('block_id', (string) $w['blockA']->id)
        ->assertSee('Plot A1')
        ->assertSee('Plot A2');

    expect(offeredPlotIds($c))->toBe(sortedIds($w['a1'], $w['a2']));
});

it('Project + Block B shows only Block B plots (2)', function () {
    $w = plotFilterWorld();

    $c = Livewire::actingAs(bookingManager())->test(BookingForm::class)
        ->set('project_id', (string) $w['project']->id)
        ->set('block_id', (string) $w['blockB']->id);

    expect(offeredPlotIds($c))->toBe(sortedIds($w['b1']));
});

it('Project + Direct Project Plot shows only block_id NULL plots, never Block plots (3, 5)', function () {
    $w = plotFilterWorld();

    $c = Livewire::actingAs(bookingManager())->test(BookingForm::class)
        ->set('project_id', (string) $w['project']->id)
        ->set('block_id', BookingForm::DIRECT_BLOCK);

    expect(offeredPlotIds($c))->toBe(sortedIds($w['d100'], $w['d102']));
});

it('shows no plots until a Block (or Direct) is chosen, so kinds are never mixed', function () {
    $w = plotFilterWorld();

    $c = Livewire::actingAs(bookingManager())->test(BookingForm::class)
        ->set('project_id', (string) $w['project']->id);

    expect(offeredPlotIds($c))->toBe([]);
});

it('keeps the existing availability rule — Booked/archived plots are not offered, Hold plots are', function () {
    $w = plotFilterWorld();
    $w['a1']->forceFill(['status' => PlotStatus::Booked])->save();
    $w['a2']->forceFill(['status' => PlotStatus::Hold])->save();
    $a3 = Plot::factory()->forBlock($w['blockA'])->create(['plot_number' => 'A3', 'status' => PlotStatus::Available->value, 'is_active' => false]);

    $c = Livewire::actingAs(bookingManager())->test(BookingForm::class)
        ->set('project_id', (string) $w['project']->id)
        ->set('block_id', (string) $w['blockA']->id);

    expect(offeredPlotIds($c))->toBe(sortedIds($w['a2']))
        ->and(offeredPlotIds($c))->not->toContain($a3->id);
});

it('changing Block A → Block B resets the selected plot (6)', function () {
    $w = plotFilterWorld();

    Livewire::actingAs(bookingManager())->test(BookingForm::class)
        ->set('project_id', (string) $w['project']->id)
        ->set('block_id', (string) $w['blockA']->id)
        ->set('plot_id', (string) $w['a1']->id)
        ->set('block_id', (string) $w['blockB']->id)
        ->assertSet('plot_id', '');
});

it('changing the Project resets both Block and Plot (7)', function () {
    $w = plotFilterWorld();
    $other = Project::factory()->create();

    Livewire::actingAs(bookingManager())->test(BookingForm::class)
        ->set('project_id', (string) $w['project']->id)
        ->set('block_id', (string) $w['blockA']->id)
        ->set('plot_id', (string) $w['a1']->id)
        ->set('project_id', (string) $other->id)
        ->assertSet('block_id', '')
        ->assertSet('plot_id', '');
});

it('unrelated field changes do not reset the selected plot', function () {
    $w = plotFilterWorld();

    Livewire::actingAs(bookingManager())->test(BookingForm::class)
        ->set('project_id', (string) $w['project']->id)
        ->set('block_id', (string) $w['blockA']->id)
        ->set('plot_id', (string) $w['a1']->id)
        ->set('notes', 'corner plot')
        ->set('base_rate', '2500')
        ->assertSet('block_id', (string) $w['blockA']->id)
        ->assertSet('plot_id', (string) $w['a1']->id);
});

it('server-side validation rejects a Block A booking with a Block B plot (8)', function () {
    $w = plotFilterWorld();
    $buyer = Buyer::factory()->create(['status' => 'active']);

    Livewire::actingAs(bookingManager())->test(BookingForm::class)
        ->set('project_id', (string) $w['project']->id)
        ->set('block_id', (string) $w['blockA']->id)
        ->set('plot_id', (string) $w['b1']->id) // manipulated request
        ->set('base_area', '1000')->set('base_rate', '2000')
        ->set('buyers.0.buyer_id', (string) $buyer->id)
        ->call('save')
        ->assertHasErrors(['plot_id']);

    expect(Booking::count())->toBe(0);
});

it('server-side validation rejects a direct booking with a block plot, and a cross-project plot (9)', function () {
    $w = plotFilterWorld();
    $buyer = Buyer::factory()->create(['status' => 'active']);

    foreach ([$w['a1'], $w['otherPlot']] as $plot) {
        Livewire::actingAs(bookingManager())->test(BookingForm::class)
            ->set('project_id', (string) $w['project']->id)
            ->set('block_id', BookingForm::DIRECT_BLOCK)
            ->set('plot_id', (string) $plot->id)
            ->set('base_area', '1000')->set('base_rate', '2000')
            ->set('buyers.0.buyer_id', (string) $buyer->id)
            ->call('save')
            ->assertHasErrors(['plot_id']);
    }

    expect(Booking::count())->toBe(0);
});

it('creates a booking for a Block A plot through the form, storing the block (10)', function () {
    $w = plotFilterWorld();
    $buyer = Buyer::factory()->create(['status' => 'active']);

    Livewire::actingAs(bookingManager())->test(BookingForm::class)
        ->set('project_id', (string) $w['project']->id)
        ->set('block_id', (string) $w['blockA']->id)
        ->set('plot_id', (string) $w['a2']->id)
        ->set('base_area', '1000')->set('base_rate', '2000')
        ->set('buyers.0.buyer_id', (string) $buyer->id)
        ->call('save')
        ->assertHasNoErrors();

    $booking = Booking::where('plot_id', $w['a2']->id)->firstOrFail();
    expect($booking->project_id)->toBe($w['project']->id)
        ->and($booking->block_id)->toBe($w['blockA']->id);
});
