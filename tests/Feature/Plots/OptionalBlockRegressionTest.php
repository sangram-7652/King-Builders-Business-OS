<?php

declare(strict_types=1);

/**
 * Regression coverage for the UrlGenerationException reported after Block
 * became optional: any screen that hardcoded `route('plots.show', [...,
 * 'block' => $x->block_id, ...])` throws once `block_id` is genuinely NULL
 * (a direct project plot), because the block-scoped route REQUIRES that
 * segment. Two real call sites were missed in the original Optional Block
 * pass — booking-show.blade.php and reports/inventory.blade.php — both now
 * route through App\Support\Plots\PlotRoutes, the single place that decides
 * which of the two route groups (block-scoped vs "direct") a given Plot
 * belongs to.
 */

use App\Enums\PlotStatus;
use App\Livewire\Bookings\BookingShow;
use App\Livewire\Plots\PlotShow;
use App\Models\Block;
use App\Models\Booking;
use App\Models\Plot;
use App\Models\Project;
use App\Support\Plots\PlotRoutes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(fn () => seedRbac());

/*
| ---------------------------------------------------------------------------
| 1-2. Booking show — the exact reported regression.
| ---------------------------------------------------------------------------
*/

it('booking show works with a block-based plot (1)', function () {
    $s = confirmedBookingScenario();

    Livewire::actingAs(bookingManager())
        ->test(BookingShow::class, ['booking' => $s['booking']])
        ->assertOk()
        ->assertSee($s['booking']->block->name)
        ->assertSee($s['booking']->plot->plot_number);
});

it('booking show works with a direct plot, without throwing UrlGenerationException (2)', function () {
    $project = Project::factory()->create();
    $plot = Plot::factory()->direct()->create(['project_id' => $project->id, 'status' => PlotStatus::Booked->value]);
    $booking = Booking::factory()->confirmed()->forPlot($plot)->create();

    $html = Livewire::actingAs(bookingManager())
        ->test(BookingShow::class, ['booking' => $booking])
        ->assertOk()
        ->assertSee($plot->plot_number)
        ->html();

    expect($html)->toContain('—')
        ->and($booking->fresh()->block_id)->toBeNull();
});

it('the booking show "Plot" link resolves to the direct route for a direct plot, and the block route otherwise (7)', function () {
    $s = confirmedBookingScenario();
    $blockHtml = Livewire::actingAs(bookingManager())->test(BookingShow::class, ['booking' => $s['booking']])->html();
    $blockRoute = PlotRoutes::forPlot($s['booking']->plot, 'show');
    expect($blockHtml)->toContain(route($blockRoute['name'], $blockRoute['params']));

    $project = Project::factory()->create();
    $plot = Plot::factory()->direct()->create(['project_id' => $project->id, 'status' => PlotStatus::Booked->value]);
    $booking = Booking::factory()->confirmed()->forPlot($plot)->create();
    $directHtml = Livewire::actingAs(bookingManager())->test(BookingShow::class, ['booking' => $booking])->html();
    $directRoute = PlotRoutes::forPlot($plot, 'show');
    expect($directRoute['name'])->toBe('plots.direct.show')
        ->and($directHtml)->toContain(route($directRoute['name'], $directRoute['params']));
});

/*
| ---------------------------------------------------------------------------
| 3-4. Plot show (already covered by OptionalBlockTest, re-asserted here
| alongside the booking-show regression for one clear regression suite).
| ---------------------------------------------------------------------------
*/

it('plot show works with a block-based plot (3)', function () {
    $block = Block::factory()->create();
    $plot = Plot::factory()->forBlock($block)->create();

    Livewire::actingAs(plotManager())
        ->test(PlotShow::class, ['project' => $block->project, 'block' => $block, 'plot' => $plot])
        ->assertOk()
        ->assertSee($block->name);
});

it('plot show works with a direct plot (4)', function () {
    $project = Project::factory()->create();
    $plot = Plot::factory()->direct()->create(['project_id' => $project->id]);

    Livewire::actingAs(plotManager())
        ->test(PlotShow::class, ['project' => $project, 'plot' => $plot])
        ->assertOk();
});

/*
| ---------------------------------------------------------------------------
| 6. Project inventory / reports — the second missed call site.
| ---------------------------------------------------------------------------
*/

it('the Inventory report "available plots" list links a direct plot to the direct route, not the block route (6, 7)', function () {
    $project = Project::factory()->create();
    $plot = Plot::factory()->direct()->create(['project_id' => $project->id, 'status' => PlotStatus::Available->value]);
    $viewer = makeUser(permissions: ['reports.view', 'plots.view']);

    $response = $this->actingAs($viewer)->get(route('reports.inventory'));

    $response->assertOk();
    $directRoute = PlotRoutes::forPlot($plot, 'show');
    expect($directRoute['name'])->toBe('plots.direct.show');
    $response->assertSee(route($directRoute['name'], $directRoute['params']), false);
});

/*
| ---------------------------------------------------------------------------
| 9. Existing block-based routes/links continue working unchanged.
| ---------------------------------------------------------------------------
*/

it('the Inventory report still links a block-based plot to the block route (9)', function () {
    $block = Block::factory()->create();
    $plot = Plot::factory()->forBlock($block)->create(['status' => PlotStatus::Available->value]);
    $viewer = makeUser(permissions: ['reports.view', 'plots.view']);

    $response = $this->actingAs($viewer)->get(route('reports.inventory'));

    $response->assertOk();
    $blockRoute = PlotRoutes::forPlot($plot, 'show');
    expect($blockRoute['name'])->toBe('plots.show');
    $response->assertSee(route($blockRoute['name'], $blockRoute['params']), false);
});

/*
| ---------------------------------------------------------------------------
| 10. Unauthorized users remain blocked, for both plot kinds.
| ---------------------------------------------------------------------------
*/

it('an unauthorised user cannot open the booking show page regardless of plot kind (10)', function () {
    $s = confirmedBookingScenario();
    $noPermission = makeUser();

    Livewire::actingAs($noPermission)
        ->test(BookingShow::class, ['booking' => $s['booking']])
        ->assertForbidden();
});

it('an unauthorised user cannot open a direct plot\'s show page (10)', function () {
    $project = Project::factory()->create();
    $plot = Plot::factory()->direct()->create(['project_id' => $project->id]);
    $noPermission = makeUser();

    $this->actingAs($noPermission)
        ->get(route('plots.direct.show', ['project' => $project->id, 'plot' => $plot->id]))
        ->assertForbidden();
});
