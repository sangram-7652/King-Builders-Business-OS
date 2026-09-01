<?php

declare(strict_types=1);

use App\Livewire\Plots\PlotBulkCreate;
use App\Livewire\Plots\PlotForm;
use App\Livewire\Plots\PlotIndex;
use App\Models\Block;
use App\Models\Plot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(fn () => seedRbac());

function plotUrl(Block $block, string $suffix = ''): string
{
    return "/projects/{$block->project_id}/blocks/{$block->id}/plots{$suffix}";
}

it('redirects a guest to login', function () {
    $block = Block::factory()->create();
    $this->get(plotUrl($block))->assertRedirect('/login');
});

it('forbids a user without plots.view', function () {
    $block = Block::factory()->create();
    $this->actingAs(makeUser())->get(plotUrl($block))->assertForbidden();
});

it('allows plots.view to list but not create / bulk', function () {
    $block = Block::factory()->create();
    $viewer = makeUser(permissions: ['plots.view']);

    $this->actingAs($viewer)->get(plotUrl($block))->assertOk();
    $this->actingAs($viewer)->get(plotUrl($block, '/create'))->assertForbidden();
    $this->actingAs($viewer)->get(plotUrl($block, '/bulk'))->assertForbidden();
});

it('requires plots.hold to place a hold and plots.release to release it', function () {
    $block = Block::factory()->create();
    $plot = Plot::factory()->forBlock($block)->available()->create();

    $noHold = makeUser(permissions: ['plots.view']);
    Livewire::actingAs($noHold)
        ->test(PlotIndex::class, ['project' => $block->project, 'block' => $block])
        ->call('startHold', $plot->id)
        ->assertForbidden();

    $holder = makeUser(permissions: ['plots.view', 'plots.hold']);
    Livewire::actingAs($holder)
        ->test(PlotIndex::class, ['project' => $block->project, 'block' => $block])
        ->call('startHold', $plot->id)
        ->set('holdReason', 'x')
        ->call('confirmHold');
    expect($plot->fresh()->status->value)->toBe('hold');

    // The holder cannot release (no plots.release).
    Livewire::actingAs($holder)
        ->test(PlotIndex::class, ['project' => $block->project, 'block' => $block])
        ->call('release', $plot->id)
        ->assertForbidden();

    $releaser = makeUser(permissions: ['plots.view', 'plots.release']);
    Livewire::actingAs($releaser)
        ->test(PlotIndex::class, ['project' => $block->project, 'block' => $block])
        ->call('release', $plot->id);
    expect($plot->fresh()->status->value)->toBe('available');
});

it('requires plots.bulk_create for the bulk screen', function () {
    $block = Block::factory()->create();

    Livewire::actingAs(makeUser(permissions: ['plots.view', 'plots.create']))
        ->test(PlotBulkCreate::class, ['project' => $block->project, 'block' => $block])
        ->assertForbidden();
});

it('blocks the create component for a view-only user', function () {
    $block = Block::factory()->create();

    Livewire::actingAs(makeUser(permissions: ['plots.view']))
        ->test(PlotForm::class, ['project' => $block->project, 'block' => $block])
        ->assertForbidden();
});

it('404s a plot URL whose block belongs to a different project', function () {
    $blockA = Block::factory()->create();
    $blockB = Block::factory()->create();
    $plotB = Plot::factory()->forBlock($blockB)->create();

    $this->actingAs(plotManager())
        ->get("/projects/{$blockA->project_id}/blocks/{$blockA->id}/plots/{$plotB->id}")
        ->assertNotFound();
});

it('lets a Super Admin reach every plot screen', function () {
    $block = Block::factory()->create();
    $plot = Plot::factory()->forBlock($block)->create();
    $this->actingAs(superAdmin());

    $this->get(plotUrl($block))->assertOk();
    $this->get(plotUrl($block, '/create'))->assertOk();
    $this->get(plotUrl($block, '/bulk'))->assertOk();
    $this->get(plotUrl($block, "/{$plot->id}"))->assertOk();
    $this->get(plotUrl($block, "/{$plot->id}/edit"))->assertOk();
});
