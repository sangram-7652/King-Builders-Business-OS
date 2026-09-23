<?php

declare(strict_types=1);

use App\Actions\Bookings\CreateBookingAction;
use App\Actions\Plots\CreatePlot;
use App\Actions\Plots\UpdatePlot;
use App\Actions\Transfer\ExecutePlotTransferAction;
use App\Enums\BookingStatus;
use App\Enums\PlotStatus;
use App\Exceptions\DomainException;
use App\Livewire\Bookings\BookingForm;
use App\Livewire\Plots\PlotForm;
use App\Livewire\Plots\PlotIndex;
use App\Livewire\Plots\PlotShow;
use App\Livewire\Plots\ProjectInventory;
use App\Models\Block;
use App\Models\Booking;
use App\Models\Buyer;
use App\Models\Plot;
use App\Models\Project;
use App\Models\TransferRequest;
use App\Models\User;
use App\Support\Plots\PlotRoutes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(fn () => seedRbac());

/**
 * A complete, already-validated Plot data array — CreatePlot/UpdatePlot
 * expect every key to be present (as PlotForm's own validate() always
 * provides), so direct Action-layer tests must supply the same shape.
 */
function plotData(array $overrides = []): array
{
    return array_merge([
        'plot_number' => 'P-'.fake()->unique()->numerify('#####'),
        'plot_category_id' => null,
        'plot_size_id' => null,
        'plot_dimension_id' => null,
        'area' => '1000',
        'area_unit' => 'sq_ft',
        'facing' => null,
        'village_name' => null,
        'gata_number' => null,
        'boundary_east' => null,
        'boundary_west' => null,
        'boundary_north' => null,
        'boundary_south' => null,
        'is_active' => true,
    ], $overrides);
}

/*
| ---------------------------------------------------------------------------
| 1-2. Create with / without a Block.
| ---------------------------------------------------------------------------
*/

it('creates a plot with a Block (1)', function () {
    $block = Block::factory()->create();

    $plot = app(CreatePlot::class)->handle($block->project, $block, plotData(['plot_number' => 'A1']));

    expect($plot->block_id)->toBe($block->id)
        ->and($plot->project_id)->toBe($block->project_id);
});

it('creates a plot without a Block (2)', function () {
    $project = Project::factory()->create();

    $plot = app(CreatePlot::class)->handle($project, null, plotData(['plot_number' => 'D1']));

    expect($plot->block_id)->toBeNull()
        ->and($plot->project_id)->toBe($project->id);
});

/*
| ---------------------------------------------------------------------------
| 3-4. Direct plot shape.
| ---------------------------------------------------------------------------
*/

it('a direct plot has block_id = NULL (3)', function () {
    $plot = Plot::factory()->direct()->create();

    expect($plot->fresh()->block_id)->toBeNull()
        ->and($plot->isDirect())->toBeTrue();
});

it('a direct plot still requires a project_id (4)', function () {
    $project = Project::factory()->create();
    $plot = Plot::factory()->direct()->create(['project_id' => $project->id]);

    expect($plot->fresh()->project_id)->toBe($project->id)
        ->and($plot->fresh()->project_id)->not->toBeNull();
});

/*
| ---------------------------------------------------------------------------
| 5. Cross-project block rejection.
| ---------------------------------------------------------------------------
*/

it('cannot assign a Block from another Project (5)', function () {
    $projectA = Project::factory()->create();
    $blockB = Block::factory()->create(); // its own, different project

    expect(fn () => app(CreatePlot::class)->handle($projectA, $blockB, plotData(['plot_number' => 'X1'])))
        ->toThrow(DomainException::class, 'does not belong to this project');
});

it('rejects a cross-project Block through the PlotForm (edit) validation too', function () {
    $projectA = Project::factory()->create();
    $plot = Plot::factory()->direct()->create(['project_id' => $projectA->id, 'plot_number' => 'X1']);
    $blockB = Block::factory()->create();

    Livewire::actingAs(plotManager())
        ->test(PlotForm::class, ['project' => $projectA, 'plot' => $plot])
        ->set('block_id', (string) $blockB->id)
        ->call('save')
        ->assertHasErrors(['block_id']);

    expect($plot->fresh()->block_id)->toBeNull();
});

it('on create, the Block comes from the context — a client-supplied block_id is ignored', function () {
    $projectA = Project::factory()->create();
    $blockB = Block::factory()->create();

    Livewire::actingAs(plotManager())
        ->test(PlotForm::class, ['project' => $projectA])
        ->set('plot_number', 'X1')
        ->set('block_id', (string) $blockB->id)
        ->set('area', '1000')
        ->call('save')
        ->assertHasNoErrors();

    $plot = Plot::where('plot_number', 'X1')->firstOrFail();
    expect($plot->project_id)->toBe($projectA->id)
        ->and($plot->block_id)->toBeNull();
});

/*
| ---------------------------------------------------------------------------
| 6. Existing Block-based plots keep working unchanged.
| ---------------------------------------------------------------------------
*/

it('an existing Block-based plot continues working exactly as before (6)', function () {
    $block = Block::factory()->create();
    $plot = Plot::factory()->forBlock($block)->create();

    Livewire::actingAs(plotManager())
        ->test(PlotShow::class, ['project' => $block->project, 'block' => $block, 'plot' => $plot])
        ->assertOk()
        ->assertSee($block->name);

    expect($plot->fresh()->block_id)->toBe($block->id);
});

/*
| ---------------------------------------------------------------------------
| 7-8. Editing the Block relationship.
| ---------------------------------------------------------------------------
*/

it('edits a plot from a Block to None (7)', function () {
    $block = Block::factory()->create();
    $plot = Plot::factory()->forBlock($block)->create();

    $updated = app(UpdatePlot::class)->handle($plot, plotData([
        'plot_number' => $plot->plot_number, 'area' => (string) $plot->area, 'block_id' => null,
    ]));

    expect($updated->block_id)->toBeNull()
        ->and($updated->project_id)->toBe($block->project_id);
});

it('edits a plot from None to a Block (8)', function () {
    $project = Project::factory()->create();
    $block = Block::factory()->create(['project_id' => $project->id]);
    $plot = Plot::factory()->direct()->create(['project_id' => $project->id]);

    $updated = app(UpdatePlot::class)->handle($plot, plotData([
        'plot_number' => $plot->plot_number, 'area' => (string) $plot->area, 'block_id' => $block->id,
    ]));

    expect($updated->block_id)->toBe($block->id);
});

it('rejects moving a plot into a Block from a different project', function () {
    $plot = Plot::factory()->direct()->create();
    $otherBlock = Block::factory()->create();

    expect(fn () => app(UpdatePlot::class)->handle($plot, plotData([
        'plot_number' => $plot->plot_number, 'area' => (string) $plot->area, 'block_id' => $otherBlock->id,
    ])))->toThrow(DomainException::class, 'does not belong to this project');

    expect($plot->fresh()->block_id)->toBeNull();
});

it('refuses to change the Block while the plot has an active booking, and reports it', function () {
    $block = Block::factory()->create();
    $plot = Plot::factory()->forBlock($block)->create(['status' => PlotStatus::Booked->value]);
    Booking::factory()->pending()->forPlot($plot)->create();

    expect(fn () => app(UpdatePlot::class)->handle($plot, plotData([
        'plot_number' => $plot->plot_number, 'area' => (string) $plot->area, 'block_id' => null,
    ])))->toThrow(DomainException::class, 'active booking');

    expect($plot->fresh()->block_id)->toBe($block->id);
});

it('leaving block_id out of the update data never touches the plot\'s Block', function () {
    $block = Block::factory()->create();
    $plot = Plot::factory()->forBlock($block)->create();

    app(UpdatePlot::class)->handle($plot, plotData([
        'plot_number' => 'RENUMBERED', 'area' => (string) $plot->area,
    ]));

    expect($plot->fresh()->block_id)->toBe($block->id)
        ->and($plot->fresh()->plot_number)->toBe('RENUMBERED');
});

/*
| ---------------------------------------------------------------------------
| 9-10. Project inventory + display.
| ---------------------------------------------------------------------------
*/

it('the Project inventory shows both block-based and direct plots (9)', function () {
    $project = Project::factory()->create();
    $block = Block::factory()->create(['project_id' => $project->id]);
    Plot::factory()->forBlock($block)->create();
    Plot::factory()->direct()->create(['project_id' => $project->id, 'plot_number' => 'D1']);

    Livewire::actingAs(projectManager())
        ->test(ProjectInventory::class, ['project' => $project])
        ->assertOk()
        ->assertSee($block->name)
        ->assertSee('Direct Project Plots')
        ->assertSee('D1');
});

it('hides the Direct Project Plots section entirely when there are none', function () {
    $project = Project::factory()->create();
    $block = Block::factory()->create(['project_id' => $project->id]);
    Plot::factory()->forBlock($block)->create();

    Livewire::actingAs(projectManager())
        ->test(ProjectInventory::class, ['project' => $project])
        ->assertOk()
        ->assertDontSee('Direct Project Plots');
});

it('a direct plot displays Block = — on its show page (10)', function () {
    $project = Project::factory()->create();
    $plot = Plot::factory()->direct()->create(['project_id' => $project->id]);

    Livewire::actingAs(plotManager())
        ->test(PlotShow::class, ['project' => $project, 'plot' => $plot])
        ->assertOk()
        ->assertSee('Direct Project Plot')
        ->assertDontSee('Fatal')
        ->assertSeeText('—');
});

it('the direct Plot index lists only plots with no block, scoped to the project', function () {
    $project = Project::factory()->create();
    $block = Block::factory()->create(['project_id' => $project->id]);
    Plot::factory()->forBlock($block)->create(['plot_number' => 'A1']);
    Plot::factory()->direct()->create(['project_id' => $project->id, 'plot_number' => 'D1']);

    Livewire::actingAs(plotManager())
        ->test(PlotIndex::class, ['project' => $project])
        ->assertOk()
        ->assertSee('D1')
        ->assertDontSee('A1');
});

/*
| ---------------------------------------------------------------------------
| Route helper + 404 safety.
| ---------------------------------------------------------------------------
*/

it('PlotRoutes resolves the direct route name/params for a Block-less plot, and the block route otherwise', function () {
    $block = Block::factory()->create();
    $blockPlot = Plot::factory()->forBlock($block)->create();
    $directPlot = Plot::factory()->direct()->create();

    $blockRoute = PlotRoutes::forPlot($blockPlot, 'show');
    $directRoute = PlotRoutes::forPlot($directPlot, 'show');

    expect($blockRoute['name'])->toBe('plots.show')
        ->and($blockRoute['params'])->toBe(['project' => $blockPlot->project_id, 'block' => $block->id, 'plot' => $blockPlot->id])
        ->and($directRoute['name'])->toBe('plots.direct.show')
        ->and($directRoute['params'])->toBe(['project' => $directPlot->project_id, 'plot' => $directPlot->id]);
});

it('404s a direct-route plot URL for a plot that actually belongs to a Block', function () {
    $block = Block::factory()->create();
    $plot = Plot::factory()->forBlock($block)->create();

    $this->actingAs(plotManager())
        ->get(route('plots.direct.show', ['project' => $block->project_id, 'plot' => $plot->id]))
        ->assertNotFound();
});

it('404s a block-route plot URL for a plot that is actually direct', function () {
    $block = Block::factory()->create();
    $plot = Plot::factory()->direct()->create(['project_id' => $block->project_id]);

    $this->actingAs(plotManager())
        ->get(route('plots.show', ['project' => $block->project_id, 'block' => $block->id, 'plot' => $plot->id]))
        ->assertNotFound();
});

/*
| ---------------------------------------------------------------------------
| Duplicate plot_number safety among direct plots (NULL isn't DB-unique-safe).
| ---------------------------------------------------------------------------
*/

it('rejects a duplicate plot_number between two direct plots in the same project', function () {
    $project = Project::factory()->create();
    Plot::factory()->direct()->create(['project_id' => $project->id, 'plot_number' => 'D1']);

    expect(fn () => app(CreatePlot::class)->handle($project, null, plotData(['plot_number' => 'D1'])))
        ->toThrow(DomainException::class, 'already exists as a direct project plot');
});

it('allows the SAME plot_number for a direct plot and a block plot in the same project', function () {
    $project = Project::factory()->create();
    $block = Block::factory()->create(['project_id' => $project->id]);
    Plot::factory()->forBlock($block)->create(['plot_number' => 'D1']);

    $plot = app(CreatePlot::class)->handle($project, null, plotData(['plot_number' => 'D1']));

    expect($plot->block_id)->toBeNull();
});

/*
| ---------------------------------------------------------------------------
| 11-12. Booking a direct plot.
| ---------------------------------------------------------------------------
*/

it('a direct plot can be booked (11)', function () {
    $project = Project::factory()->create();
    $plot = Plot::factory()->direct()->create(['project_id' => $project->id, 'status' => PlotStatus::Available->value]);
    $buyer = Buyer::factory()->create(['status' => 'active']);
    $actor = User::factory()->create();

    $booking = app(CreateBookingAction::class)->handle([
        'project_id' => $project->id,
        'block_id' => null,
        'plot_id' => $plot->id,
        'booking_date' => now()->toDateString(),
        'status' => BookingStatus::Pending->value,
        'pricing' => ['base_area' => '1000', 'base_rate' => '2000', 'components' => []],
        'buyers' => [['buyer_id' => $buyer->id, 'ownership_percentage' => '100', 'is_primary' => true]],
    ], $actor);

    expect($booking->plot_id)->toBe($plot->id)
        ->and($booking->block_id)->toBeNull()
        ->and($booking->project_id)->toBe($project->id);
});

it('booking correctly stores a nullable block_id derived from the plot, not the form (12)', function () {
    $project = Project::factory()->create();
    $plot = Plot::factory()->direct()->create(['project_id' => $project->id, 'status' => PlotStatus::Available->value]);
    $buyer = Buyer::factory()->create(['status' => 'active']);
    $actor = User::factory()->create();

    $booking = app(CreateBookingAction::class)->handle([
        'project_id' => $project->id,
        'block_id' => null,
        'plot_id' => $plot->id,
        'booking_date' => now()->toDateString(),
        'pricing' => ['base_area' => '1000', 'base_rate' => '2000', 'components' => []],
        'buyers' => [['buyer_id' => $buyer->id, 'ownership_percentage' => '100', 'is_primary' => true]],
    ], $actor);

    expect($booking->fresh()->block_id)->toBeNull();
});

it('rejects a booking whose submitted block_id does not match the direct plot\'s hierarchy', function () {
    $project = Project::factory()->create();
    $block = Block::factory()->create(['project_id' => $project->id]);
    $plot = Plot::factory()->direct()->create(['project_id' => $project->id, 'status' => PlotStatus::Available->value]);
    $buyer = Buyer::factory()->create(['status' => 'active']);
    $actor = User::factory()->create();

    expect(fn () => app(CreateBookingAction::class)->handle([
        'project_id' => $project->id,
        'block_id' => $block->id, // wrong — this plot is direct
        'plot_id' => $plot->id,
        'booking_date' => now()->toDateString(),
        'pricing' => ['base_area' => '1000', 'base_rate' => '2000', 'components' => []],
        'buyers' => [['buyer_id' => $buyer->id, 'ownership_percentage' => '100', 'is_primary' => true]],
    ], $actor))->toThrow(DomainException::class);
});

it('the BookingForm cascading picker offers Direct Project Plot and correctly submits a null block_id', function () {
    $project = Project::factory()->create();
    $directPlot = Plot::factory()->direct()->create(['project_id' => $project->id, 'status' => PlotStatus::Available->value]);
    $buyer = Buyer::factory()->create(['status' => 'active']);

    Livewire::actingAs(bookingManager())
        ->test(BookingForm::class)
        ->set('project_id', (string) $project->id)
        ->assertSee('Direct Project Plot')
        ->set('block_id', BookingForm::DIRECT_BLOCK)
        ->set('plot_id', (string) $directPlot->id)
        ->set('base_area', '1000')
        ->set('base_rate', '2000')
        ->set('buyers.0.buyer_id', (string) $buyer->id)
        ->call('save')
        ->assertHasNoErrors();

    $booking = Booking::where('plot_id', $directPlot->id)->firstOrFail();
    expect($booking->block_id)->toBeNull();
});

/*
| ---------------------------------------------------------------------------
| 13-15. Plot Transfer across Block / Direct combinations.
| (ExecutePlotTransferAction never referenced block_id at all — it already
| supports every combination; these tests document and lock that in.)
| ---------------------------------------------------------------------------
*/

it('transfers a direct plot to another direct plot (13)', function () {
    $project = Project::factory()->create();
    $oldPlot = Plot::factory()->direct()->create(['project_id' => $project->id, 'status' => PlotStatus::Booked->value]);
    $newPlot = Plot::factory()->direct()->create(['project_id' => $project->id, 'status' => PlotStatus::Available->value]);
    $booking = Booking::factory()->confirmed()->forPlot($oldPlot)->create();

    $transfer = app(ExecutePlotTransferAction::class)->handle($booking->fresh(), $newPlot->id, null, possessionOfficer());

    expect($booking->fresh()->plot_id)->toBe($newPlot->id)
        ->and($booking->fresh()->block_id)->toBeNull()
        ->and($oldPlot->fresh()->status)->toBe(PlotStatus::Available)
        ->and($newPlot->fresh()->status)->toBe(PlotStatus::Booked)
        ->and($transfer->plot->block_id)->toBeNull()
        ->and($transfer->newPlot->block_id)->toBeNull();
});

it('transfers a direct plot to a Block plot (14)', function () {
    $project = Project::factory()->create();
    $block = Block::factory()->create(['project_id' => $project->id]);
    $oldPlot = Plot::factory()->direct()->create(['project_id' => $project->id, 'status' => PlotStatus::Booked->value]);
    $newPlot = Plot::factory()->forBlock($block)->create(['status' => PlotStatus::Available->value]);
    $booking = Booking::factory()->confirmed()->forPlot($oldPlot)->create();

    app(ExecutePlotTransferAction::class)->handle($booking->fresh(), $newPlot->id, null, possessionOfficer());

    expect($booking->fresh()->plot_id)->toBe($newPlot->id)
        ->and($booking->fresh()->block_id)->toBe($block->id)
        ->and($booking->fresh()->project_id)->toBe($project->id);
});

it('transfers a Block plot to a direct plot (15)', function () {
    $project = Project::factory()->create();
    $block = Block::factory()->create(['project_id' => $project->id]);
    $oldPlot = Plot::factory()->forBlock($block)->create(['status' => PlotStatus::Booked->value]);
    $newPlot = Plot::factory()->direct()->create(['project_id' => $project->id, 'status' => PlotStatus::Available->value]);
    $booking = Booking::factory()->confirmed()->forPlot($oldPlot)->create();

    app(ExecutePlotTransferAction::class)->handle($booking->fresh(), $newPlot->id, null, possessionOfficer());

    expect($booking->fresh()->plot_id)->toBe($newPlot->id)
        ->and($booking->fresh()->block_id)->toBeNull();
});

it('a Block plot can still transfer to another plot in the SAME Block (regression)', function () {
    $block = Block::factory()->create();
    $oldPlot = Plot::factory()->forBlock($block)->create(['status' => PlotStatus::Booked->value]);
    $newPlot = Plot::factory()->forBlock($block)->create(['status' => PlotStatus::Available->value]);
    $booking = Booking::factory()->confirmed()->forPlot($oldPlot)->create();

    app(ExecutePlotTransferAction::class)->handle($booking->fresh(), $newPlot->id, null, possessionOfficer());

    expect($booking->fresh()->plot_id)->toBe($newPlot->id)
        ->and($booking->fresh()->block_id)->toBe($block->id);
});

/*
| ---------------------------------------------------------------------------
| 16. Transfer history stores old/new block values, including NULL.
| ---------------------------------------------------------------------------
*/

it('Transfer History correctly stores old/new plot (and therefore block, via the plot) including NULL (16)', function () {
    $project = Project::factory()->create();
    $block = Block::factory()->create(['project_id' => $project->id]);
    $oldPlot = Plot::factory()->forBlock($block)->create(['status' => PlotStatus::Booked->value]);
    $newPlot = Plot::factory()->direct()->create(['project_id' => $project->id, 'status' => PlotStatus::Available->value]);
    $booking = Booking::factory()->confirmed()->forPlot($oldPlot)->create();

    $transfer = app(ExecutePlotTransferAction::class)->handle($booking->fresh(), $newPlot->id, 'consolidating', possessionOfficer());

    $fresh = TransferRequest::with(['plot', 'newPlot'])->findOrFail($transfer->id);
    expect($fresh->plot_id)->toBe($oldPlot->id)
        ->and($fresh->plot->block_id)->toBe($block->id)
        ->and($fresh->new_plot_id)->toBe($newPlot->id)
        ->and($fresh->newPlot->block_id)->toBeNull()
        ->and($fresh->reason)->toBe('consolidating');
});
