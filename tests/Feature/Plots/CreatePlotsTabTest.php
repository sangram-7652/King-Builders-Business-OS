<?php

declare(strict_types=1);

use App\Actions\Bookings\CreateBookingAction;
use App\Actions\Transfer\ExecutePlotTransferAction;
use App\Enums\BookingStatus;
use App\Enums\PlotStatus;
use App\Livewire\Plots\PlotForm;
use App\Livewire\Plots\PlotIndex;
use App\Livewire\Plots\PlotShow;
use App\Livewire\Plots\ProjectInventory;
use App\Livewire\Projects\ProjectShow;
use App\Models\Block;
use App\Models\Booking;
use App\Models\Buyer;
use App\Models\Plot;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(fn () => seedRbac());

function projectPlotManager(): User
{
    return makeUser(permissions: [
        'projects.view', 'projects.update',
        'plots.view', 'plots.create', 'plots.update', 'plots.delete', 'plots.bulk_create',
    ]);
}

/** @return array<string, mixed> */
function bookingDataFor(Plot $plot): array
{
    return [
        'project_id' => $plot->project_id,
        'block_id' => $plot->block_id,
        'plot_id' => $plot->id,
        'booking_date' => now()->toDateString(),
        'status' => BookingStatus::Pending->value,
        'pricing' => ['base_area' => '1000', 'base_rate' => '2000', 'components' => []],
        'buyers' => [['buyer_id' => Buyer::factory()->create(['status' => 'active'])->id, 'ownership_percentage' => '100', 'is_primary' => true]],
    ];
}

/*
| ---------------------------------------------------------------------------
| Creation — Block-wise (unchanged) and Project → Create Plots (direct).
| ---------------------------------------------------------------------------
*/

it('Project → Block → Create Plot still creates a plot in that Block (1)', function () {
    $block = Block::factory()->create();

    Livewire::actingAs(projectPlotManager())
        ->test(PlotForm::class, ['project' => $block->project, 'block' => $block])
        ->assertSee($block->name)
        ->set('plot_number', 'A1')
        ->set('area', '1000')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('plots.show', ['project' => $block->project_id, 'block' => $block->id, 'plot' => Plot::where('plot_number', 'A1')->value('id')]));

    $plot = Plot::where('plot_number', 'A1')->firstOrFail();
    expect($plot->project_id)->toBe($block->project_id)
        ->and($plot->block_id)->toBe($block->id);
});

it('the Project page has a Create Plots tab for users who can create plots', function () {
    $project = Project::factory()->create();

    Livewire::actingAs(projectPlotManager())
        ->test(ProjectShow::class, ['project' => $project])
        ->assertSee('Create Plots')
        ->call('setTab', 'create-plots')
        ->assertSet('tab', 'create-plots')
        ->assertSee('with no Block');
});

it('hides the Create Plots tab from users without plots.create', function () {
    $project = Project::factory()->create();

    Livewire::actingAs(makeUser(permissions: ['projects.view', 'plots.view']))
        ->test(ProjectShow::class, ['project' => $project])
        ->assertDontSee('Create Plots')
        ->call('setTab', 'create-plots')
        ->assertSet('tab', 'overview');
});

it('Project → Create Plots creates a direct plot with block_id NULL and the right project (2, 3, 4)', function () {
    $project = Project::factory()->create();
    Block::factory()->create(['project_id' => $project->id]); // a Block exists, but must not be used

    Livewire::actingAs(projectPlotManager())
        ->test(PlotForm::class, ['project' => $project, 'embedded' => true])
        ->assertDontSee('Select…')
        ->set('plot_number', '101')
        ->set('area', '1200')
        ->set('village_name', 'Rampur')
        ->set('boundary_east', 'Road')
        ->call('save')
        ->assertHasNoErrors()
        ->assertNoRedirect()
        ->assertDispatched('plot-created')
        ->assertSet('plot_number', '');

    $plot = Plot::where('plot_number', '101')->firstOrFail();
    expect($plot->project_id)->toBe($project->id)
        ->and($plot->block_id)->toBeNull()
        ->and($plot->status)->toBe(PlotStatus::Available)
        ->and($plot->village_name)->toBe('Rampur')
        ->and($plot->boundary_east)->toBe('Road');
});

it('creating plots never creates a fake / placeholder Block (20)', function () {
    $project = Project::factory()->create();
    $blocksBefore = Block::withTrashed()->count();

    foreach (['101', '102'] as $number) {
        Livewire::actingAs(projectPlotManager())
            ->test(PlotForm::class, ['project' => $project, 'embedded' => true])
            ->set('plot_number', $number)->set('area', '1000')
            ->call('save')->assertHasNoErrors();
    }

    expect(Block::withTrashed()->count())->toBe($blocksBefore)
        ->and(Plot::where('project_id', $project->id)->whereNull('block_id')->count())->toBe(2)
        ->and(Plot::where('block_id', 0)->exists())->toBeFalse();
});

/*
| ---------------------------------------------------------------------------
| Project Inventory — ALL plots of the project, grouped.
| ---------------------------------------------------------------------------
*/

it('Project Inventory lists block plots under their Block and direct plots separately (5, 6, 7, 10)', function () {
    $project = Project::factory()->create();
    $blockA = Block::factory()->create(['project_id' => $project->id, 'name' => 'Block Alpha']);
    $blockB = Block::factory()->create(['project_id' => $project->id, 'name' => 'Block Beta']);
    Plot::factory()->forBlock($blockA)->create(['plot_number' => 'A1']);
    Plot::factory()->forBlock($blockA)->create(['plot_number' => 'A2']);
    Plot::factory()->forBlock($blockB)->create(['plot_number' => 'B1']);
    Plot::factory()->direct()->create(['project_id' => $project->id, 'plot_number' => '101']);
    Plot::factory()->direct()->create(['project_id' => $project->id, 'plot_number' => '102']);

    $component = Livewire::actingAs(projectPlotManager())
        ->test(ProjectInventory::class, ['project' => $project])
        ->assertOk()
        ->assertSeeInOrder(['Block Alpha', 'Plot A1', 'Plot A2', 'Block Beta', 'Plot B1', 'Direct Project Plots', 'Plot 101', 'Plot 102']);

    expect($component->get('counts')->total)->toBe(5);
});

it('Project Inventory works with only block plots (9)', function () {
    $project = Project::factory()->create();
    $block = Block::factory()->create(['project_id' => $project->id, 'name' => 'Block Alpha']);
    Plot::factory()->forBlock($block)->create(['plot_number' => 'A1']);

    Livewire::actingAs(projectPlotManager())
        ->test(ProjectInventory::class, ['project' => $project])
        ->assertSee('Block Alpha')
        ->assertSee('Plot A1')
        ->assertDontSee('Direct Project Plots');
});

it('Project Inventory works with only direct plots (8)', function () {
    $project = Project::factory()->create();
    Plot::factory()->direct()->create(['project_id' => $project->id, 'plot_number' => '101']);

    Livewire::actingAs(projectPlotManager())
        ->test(ProjectInventory::class, ['project' => $project])
        ->assertSee('Direct Project Plots')
        ->assertSee('Plot 101')
        ->assertDontSee('No plots yet');
});

it('Project Inventory never shows another project\'s plots', function () {
    $project = Project::factory()->create();
    Plot::factory()->direct()->create(['project_id' => $project->id, 'plot_number' => '101']);
    Plot::factory()->direct()->create(['plot_number' => '999']);

    Livewire::actingAs(projectPlotManager())
        ->test(ProjectInventory::class, ['project' => $project])
        ->assertSee('Plot 101')
        ->assertDontSee('Plot 999');
});

it('Project overview distinguishes total, block-based and direct plot counts without double counting', function () {
    $project = Project::factory()->create();
    $block = Block::factory()->create(['project_id' => $project->id]);
    Plot::factory()->forBlock($block)->count(3)->create();
    Plot::factory()->direct()->count(2)->create(['project_id' => $project->id]);

    $html = Livewire::actingAs(projectPlotManager())
        ->test(ProjectShow::class, ['project' => $project])
        ->html();

    expect($html)->toMatch('/Total plots<\/dt><dd[^>]*>5</')
        ->toMatch('/Block-based plots<\/dt><dd[^>]*>3</')
        ->toMatch('/Direct plots<\/dt><dd[^>]*>2</');
});

/*
| ---------------------------------------------------------------------------
| View / Edit / Delete for both plot kinds.
| ---------------------------------------------------------------------------
*/

it('direct plot View, Edit and Delete work through the project-level routes (11, 12, 13)', function () {
    $project = Project::factory()->create();
    $plot = Plot::factory()->direct()->create(['project_id' => $project->id, 'plot_number' => '101']);
    $user = projectPlotManager();

    $this->actingAs($user)->get(route('plots.direct.show', ['project' => $project->id, 'plot' => $plot->id]))->assertOk();
    $this->actingAs($user)->get(route('plots.direct.edit', ['project' => $project->id, 'plot' => $plot->id]))->assertOk();

    Livewire::actingAs($user)
        ->test(PlotShow::class, ['project' => $project, 'plot' => $plot])
        ->assertSee(route('plots.direct.edit', ['project' => $project->id, 'plot' => $plot->id]), false);

    Livewire::actingAs($user)
        ->test(PlotForm::class, ['project' => $project, 'plot' => $plot])
        ->set('area', '1500')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('plots.direct.show', ['project' => $project->id, 'plot' => $plot->id]));
    expect((string) $plot->fresh()->area)->toStartWith('1500')
        ->and($plot->fresh()->block_id)->toBeNull();

    Livewire::actingAs($user)
        ->test(PlotIndex::class, ['project' => $project])
        ->call('delete', $plot->id);
    expect(Plot::find($plot->id))->toBeNull()
        ->and(Plot::withTrashed()->find($plot->id))->not->toBeNull();
});

it('block plot View, Edit and Delete still work through the block routes (14, 15, 16)', function () {
    $block = Block::factory()->create();
    $project = $block->project;
    $plot = Plot::factory()->forBlock($block)->create(['plot_number' => 'A1']);
    $user = projectPlotManager();
    $params = ['project' => $project->id, 'block' => $block->id, 'plot' => $plot->id];

    $this->actingAs($user)->get(route('plots.show', $params))->assertOk();
    $this->actingAs($user)->get(route('plots.edit', $params))->assertOk();

    Livewire::actingAs($user)
        ->test(PlotShow::class, ['project' => $project, 'block' => $block, 'plot' => $plot])
        ->assertSee($block->name);

    Livewire::actingAs($user)
        ->test(PlotForm::class, ['project' => $project, 'block' => $block, 'plot' => $plot])
        ->set('area', '1500')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('plots.show', $params));
    expect($plot->fresh()->block_id)->toBe($block->id);

    Livewire::actingAs($user)
        ->test(PlotIndex::class, ['project' => $project, 'block' => $block])
        ->call('delete', $plot->id);
    expect(Plot::find($plot->id))->toBeNull();
});

it('a direct plot with a live booking is still protected from deletion by the existing rule', function () {
    $project = Project::factory()->create();
    $plot = Plot::factory()->direct()->create(['project_id' => $project->id, 'status' => PlotStatus::Available->value]);
    app(CreateBookingAction::class)->handle(bookingDataFor($plot), User::factory()->create());

    Livewire::actingAs(projectPlotManager())
        ->test(PlotIndex::class, ['project' => $project])
        ->call('delete', $plot->id)
        ->assertForbidden();

    expect(Plot::find($plot->id))->not->toBeNull();
});

/*
| ---------------------------------------------------------------------------
| Booking + Plot Transfer across both plot kinds.
| ---------------------------------------------------------------------------
*/

it('both a direct plot and a block plot can be booked, copying block_id from the plot (17, 18)', function () {
    $project = Project::factory()->create();
    $block = Block::factory()->create(['project_id' => $project->id]);
    $direct = Plot::factory()->direct()->create(['project_id' => $project->id, 'status' => PlotStatus::Available->value]);
    $inBlock = Plot::factory()->forBlock($block)->create(['status' => PlotStatus::Available->value]);
    $actor = User::factory()->create();

    $b1 = app(CreateBookingAction::class)->handle(bookingDataFor($direct), $actor);
    $b2 = app(CreateBookingAction::class)->handle(bookingDataFor($inBlock), $actor);

    expect([$b1->project_id, $b1->block_id, $b1->plot_id])->toBe([$project->id, null, $direct->id])
        ->and([$b2->project_id, $b2->block_id, $b2->plot_id])->toBe([$project->id, $block->id, $inBlock->id]);
});

it('Plot Transfer moves Direct → Block → Direct, updating booking.block_id each time (19)', function () {
    $project = Project::factory()->create();
    $block = Block::factory()->create(['project_id' => $project->id]);
    $direct1 = Plot::factory()->direct()->create(['project_id' => $project->id, 'status' => PlotStatus::Booked->value]);
    $inBlock = Plot::factory()->forBlock($block)->create(['status' => PlotStatus::Available->value]);
    $direct2 = Plot::factory()->direct()->create(['project_id' => $project->id, 'status' => PlotStatus::Available->value]);
    $booking = Booking::factory()->confirmed()->forPlot($direct1)->create();
    $blocksBefore = Block::count();

    app(ExecutePlotTransferAction::class)->handle($booking->fresh(), $inBlock->id, null, possessionOfficer());
    expect($booking->fresh()->block_id)->toBe($block->id);

    app(ExecutePlotTransferAction::class)->handle($booking->fresh(), $direct2->id, null, possessionOfficer());
    expect($booking->fresh()->plot_id)->toBe($direct2->id)
        ->and($booking->fresh()->block_id)->toBeNull()
        ->and($direct1->fresh()->status)->toBe(PlotStatus::Available)
        ->and($inBlock->fresh()->status)->toBe(PlotStatus::Available)
        ->and($direct2->fresh()->status)->toBe(PlotStatus::Booked)
        ->and(Block::count())->toBe($blocksBefore);
});
