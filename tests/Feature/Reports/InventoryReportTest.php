<?php

declare(strict_types=1);

use App\Enums\AgingBucket;
use App\Enums\DatePreset;
use App\Enums\PlotStatus;
use App\Enums\RegistryStatus;
use App\Models\Block;
use App\Models\Booking;
use App\Models\Masters\PlotSize;
use App\Models\Plot;
use App\Models\Project;
use App\Services\Reports\InventoryAnalytics;
use App\Support\Reports\InventoryFilters;
use App\Support\Reports\ReportFilterData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(function () {
    seedRbac();
    $this->travelTo(Carbon::parse('2026-06-15 09:00:00', 'UTC'));
});

/** @return array<string, mixed> */
function invWorld(): array
{
    $pA = Project::factory()->create(['name' => 'Green Meadows']);
    $bA1 = Block::factory()->create(['project_id' => $pA->id, 'name' => 'Block A1']);
    $bA2 = Block::factory()->create(['project_id' => $pA->id, 'name' => 'Block A2']);
    $pB = Project::factory()->create(['name' => 'Blue Ridge']);
    $bB1 = Block::factory()->create(['project_id' => $pB->id, 'name' => 'Block B1']);

    $small = PlotSize::factory()->create(['name' => '30x40', 'area' => 1200, 'sort_order' => 1]);
    $large = PlotSize::factory()->create(['name' => '40x60', 'area' => 2400, 'sort_order' => 2]);

    $plot = fn (Block $b, string $status, array $extra = []) => Plot::factory()->create(array_merge([
        'project_id' => $b->project_id, 'block_id' => $b->id, 'status' => $status,
    ], $extra));

    // Green Meadows / A1: 5 available, 2 booked, 1 possession_completed
    collect(range(1, 5))->each(fn () => $plot($bA1, 'available', ['plot_size_id' => $small->id, 'area' => 1200]));
    $bookedA1 = collect(range(1, 2))->map(fn () => $plot($bA1, 'booked', ['plot_size_id' => $large->id, 'area' => 2400]));
    $plot($bA1, 'possession_completed', ['plot_size_id' => $large->id, 'area' => 2400]);

    // Green Meadows / A2: 3 available (one very old), 1 sold, no size
    $plot($bA2, 'available', ['created_at' => Carbon::parse('2025-11-01')]);   // ~226 days → 180+
    $plot($bA2, 'available', ['created_at' => Carbon::parse('2026-04-20')]);   // ~56 days → 31-60
    $plot($bA2, 'available');                                                 // fresh → 0-30
    $plot($bA2, 'sold');

    // Blue Ridge / B1: 3 available, 1 booked
    collect(range(1, 3))->each(fn () => $plot($bB1, 'available', ['plot_size_id' => $small->id, 'area' => 1200]));
    $plot($bB1, 'booked', ['plot_size_id' => $small->id, 'area' => 1200]);

    // One booked plot gets a confirmed booking + completed registry → "registered" = 1
    $reg = $bookedA1->first();
    Booking::factory()->confirmed()->forPlot($reg)->create([
        'final_amount' => '3500000', 'booking_date' => '2026-06-05', 'registry_status' => RegistryStatus::Done->value,
    ]);

    // A couple more confirmed bookings for price bands
    Booking::factory()->confirmed()->create(['project_id' => $pA->id, 'block_id' => $bA1->id, 'plot_id' => $bookedA1->last()->id, 'final_amount' => '1800000', 'booking_date' => '2026-06-08']);
    Booking::factory()->confirmed()->create(['project_id' => $pB->id, 'block_id' => $bB1->id, 'plot_id' => Plot::where('block_id', $bB1->id)->where('status', 'booked')->first()->id, 'final_amount' => '22000000', 'booking_date' => '2026-06-10']);

    return compact('pA', 'pB', 'bA1', 'bA2', 'bB1', 'small', 'large');
}

function invA(): InventoryAnalytics
{
    return app(InventoryAnalytics::class);
}

function noFilter(): ReportFilterData
{
    return ReportFilterData::default();
}

/*
| KPIs
*/

it('computes the inventory KPIs from the M4 state + M9/M10', function () {
    invWorld();
    $k = invA()->inventoryKpis(noFilter());

    expect($k['total'])->toBe(Plot::where('is_active', true)->count())
        ->and($k['available'])->toBe(Plot::where('status', 'available')->count())
        ->and($k['booked'])->toBe(Plot::where('status', 'booked')->count())
        ->and($k['possession'])->toBe(Plot::where('status', 'possession_completed')->count())
        ->and($k['possession'])->toBe(1)
        ->and($k['registered'])->toBe(1);   // one completed registry case
});

it('aggregates the status distribution zero-filled from PlotStatus, no invented keys', function () {
    invWorld();
    $dist = invA()->statusDistribution(noFilter());

    expect(array_keys($dist))->toBe(array_map(fn ($c) => $c->value, PlotStatus::cases()))
        ->and($dist['available'])->toBe(Plot::where('status', 'available')->count())
        ->and($dist['possession_completed'])->toBe(1)
        ->and($dist['cancelled'])->toBe(0)
        ->and(array_sum($dist))->toBe(Plot::where('is_active', true)->count());
});

/*
| FILTERING
*/

it('filters inventory by project', function () {
    $w = invWorld();
    $k = invA()->inventoryKpis(new ReportFilterData(from: noFilter()->from, to: noFilter()->to, preset: DatePreset::Custom, projectId: $w['pB']->id));

    expect($k['total'])->toBe(Plot::where('project_id', $w['pB']->id)->where('is_active', true)->count())
        ->and($k['registered'])->toBe(0);   // the registered plot is in project A
});

it('filters inventory by block', function () {
    $w = invWorld();
    $k = invA()->inventoryKpis(new ReportFilterData(from: noFilter()->from, to: noFilter()->to, preset: DatePreset::Custom, projectId: $w['pA']->id, blockId: $w['bA2']->id));

    expect($k['total'])->toBe(4)          // 3 available + 1 sold in A2
        ->and($k['available'])->toBe(3)
        ->and($k['booked'])->toBe(0);
});

/*
| ROLL-UPS
*/

it('builds the project inventory roll-up', function () {
    $w = invWorld();
    $rows = invA()->projectInventory(noFilter());

    $a = collect($rows)->firstWhere('project', 'Green Meadows');
    expect($a['total'])->toBe(Plot::where('project_id', $w['pA']->id)->where('is_active', true)->count())
        ->and($a['registered'])->toBe(1)
        ->and($a['possession'])->toBe(1)
        ->and($a['sold_pct'])->toBe(round(($a['total'] - $a['available']) / $a['total'] * 100, 1));
});

it('builds the block inventory roll-up with project name', function () {
    $w = invWorld();
    $rows = invA()->blockInventory(noFilter());

    $a2 = collect($rows)->firstWhere('block', 'Block A2');
    expect($a2['project'])->toBe('Green Meadows')
        ->and($a2['total'])->toBe(4)
        ->and($a2['available'])->toBe(3);
});

/*
| AGEING + PRICE + SIZE
*/

it('buckets available inventory ageing by time since created_at', function () {
    invWorld();
    $ageing = invA()->ageing(noFilter());

    expect(array_keys($ageing))->toBe(array_map(fn ($c) => $c->value, AgingBucket::cases()))
        ->and($ageing[AgingBucket::Days180Plus->value])->toBe(1)      // the 2025-11-01 plot
        ->and($ageing[AgingBucket::Days31To60->value])->toBe(1)      // the 2026-04-20 plot
        ->and(array_sum($ageing))->toBe(Plot::where('status', 'available')->count());
});

it('bands confirmed bookings by price', function () {
    invWorld();
    $bands = invA()->priceBands(noFilter());

    $byBand = collect($bands)->keyBy('band');
    // bookings: ₹35 L, ₹18 L, ₹2.2 Cr
    expect($byBand['Under ₹25 L']['bookings'])->toBe(1)           // ₹18 L
        ->and($byBand['₹25 L – ₹50 L']['bookings'])->toBe(1)      // ₹35 L
        ->and($byBand['₹25 L – ₹50 L']['average'])->toBe(3_500_000.0)
        ->and($byBand['₹1 Cr – ₹2 Cr']['bookings'])->toBe(0)
        ->and($byBand['₹2 Cr+']['bookings'])->toBe(1);            // ₹2.2 Cr
});

it('bands plots by the size master and groups unsized separately', function () {
    invWorld();
    $bands = invA()->sizeBands(noFilter());

    $byBand = collect($bands)->keyBy('band');
    expect($byBand->has('30x40'))->toBeTrue()
        ->and($byBand->has('40x60'))->toBeTrue()
        ->and($byBand->has('Unsized'))->toBeTrue()          // block A2 plots have no size
        ->and($byBand['30x40']['available'])->toBe(Plot::where('plot_size_id', PlotSize::where('name', '30x40')->value('id'))->where('status', 'available')->count());
});

it('applies the size range filter', function () {
    invWorld();
    $narrow = invA()->statusDistribution(noFilter(), new InventoryFilters(sizeMin: 2000));

    // only the 2400-area plots (2 booked + 1 possession in A1) survive
    expect(array_sum($narrow))->toBe(Plot::where('area', '>=', 2000)->where('is_active', true)->count());
});

/*
| AVAILABLE PLOTS LIST
*/

it('paginates the available plots list, oldest first, filter-aware', function () {
    $w = invWorld();
    $page = invA()->availablePlots(noFilter(), InventoryFilters::none(), 5);

    expect($page->total())->toBe(Plot::where('status', 'available')->count())
        ->and($page->perPage())->toBe(5)
        ->and($page->count())->toBe(5);

    $scoped = invA()->availablePlots(
        new ReportFilterData(from: noFilter()->from, to: noFilter()->to, preset: DatePreset::Custom, projectId: $w['pB']->id),
        InventoryFilters::none(),
        20,
    );
    expect($scoped->total())->toBe(3);
});

/*
| SCREEN + SECURITY + DRILL-DOWN
*/

it('renders the inventory report with its sections', function () {
    invWorld();
    $this->actingAs(makeUser(permissions: ['reports.view', 'projects.view', 'plots.view']))
        ->get(route('reports.inventory'))
        ->assertOk()
        ->assertSee('Inventory report')
        ->assertSee('Inventory status')
        ->assertSee('Inventory ageing')
        ->assertSee('Project inventory')
        ->assertSee('Block inventory')
        ->assertSee('Price bands')
        ->assertSee('Size bands')
        ->assertSee('Available plots');
});

it('forbids the inventory report without reports.view', function () {
    $this->actingAs(makeUser(permissions: ['plots.view']))->get(route('reports.inventory'))->assertForbidden();
});

it('rejects foreign filter ids and an inverted price range on the inventory report', function () {
    $me = makeUser(permissions: ['reports.view', 'projects.view']);

    $this->actingAs($me)->getJson(route('reports.inventory', ['project_id' => 999999]))
        ->assertStatus(422)->assertJsonValidationErrors('project_id');

    $pA = Project::factory()->create();
    $foreignBlock = Block::factory()->create(['project_id' => Project::factory()->create()->id]);
    $this->actingAs($me)->getJson(route('reports.inventory', ['project_id' => $pA->id, 'block_id' => $foreignBlock->id]))
        ->assertStatus(422)->assertJsonValidationErrors('block_id');

    $this->actingAs($me)->getJson(route('reports.inventory', ['price_min' => 500, 'price_max' => 100]))
        ->assertStatus(422)->assertJsonValidationErrors('price_max');
});

it('links inventory rows to the project 360, block plots list and plot 360', function () {
    $w = invWorld();
    $html = $this->actingAs(makeUser(permissions: ['reports.view', 'projects.view', 'plots.view']))
        ->get(route('reports.inventory'))
        ->assertOk()
        ->getContent();

    $availablePlot = Plot::where('status', 'available')->first();

    expect($html)->toContain(route('projects.show', $w['pA']->id))
        ->toContain(route('plots.index', ['project' => $w['pA']->id, 'block' => $w['bA1']->id]))
        ->toContain(route('plots.show', ['project' => $availablePlot->project_id, 'block' => $availablePlot->block_id, 'plot' => $availablePlot->id]));
});
