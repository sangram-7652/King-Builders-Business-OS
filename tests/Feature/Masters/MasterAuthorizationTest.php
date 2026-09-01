<?php

declare(strict_types=1);

use App\Livewire\Masters\MasterForm;
use App\Livewire\Masters\MasterIndex;
use App\Masters\MasterRegistry;
use App\Models\Masters\PlotCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(fn () => seedRbac());

it('redirects a guest away from the master data screens', function () {
    $this->get('/settings/masters')->assertRedirect('/login');
    $this->get('/settings/masters/plot-categories')->assertRedirect('/login');
});

it('forbids a user without masters.view', function () {
    $this->actingAs(makeUser())
        ->get('/settings/masters/plot-categories')
        ->assertForbidden();
});

it('allows a user with masters.view to list but not create', function () {
    $viewer = makeUser(permissions: ['masters.view']);

    $this->actingAs($viewer)->get('/settings/masters/plot-categories')->assertOk();
    $this->actingAs($viewer)->get('/settings/masters/plot-categories/create')->assertForbidden();
});

it('blocks the create action for a view-only user even when invoked directly', function () {
    $viewer = makeUser(permissions: ['masters.view']);

    Livewire::actingAs($viewer)
        ->test(MasterForm::class, ['resource' => 'plot-categories'])
        ->assertForbidden();
});

it('blocks the delete action for a user without masters.delete', function () {
    $editor = makeUser(permissions: ['masters.view', 'masters.update']);
    $category = PlotCategory::factory()->create();

    Livewire::actingAs($editor)
        ->test(MasterIndex::class, ['resource' => 'plot-categories'])
        ->call('delete', $category->id)
        ->assertForbidden();

    expect(PlotCategory::find($category->id))->not->toBeNull();
});

it('blocks the toggle-status action for a view-only user', function () {
    $viewer = makeUser(permissions: ['masters.view']);
    $category = PlotCategory::factory()->create(['is_active' => true]);

    Livewire::actingAs($viewer)
        ->test(MasterIndex::class, ['resource' => 'plot-categories'])
        ->call('toggleStatus', $category->id)
        ->assertForbidden();

    expect($category->fresh()->is_active)->toBeTrue();
});

it('lets a Super Admin reach every master screen', function () {
    $this->actingAs(superAdmin());

    $this->get('/settings/masters')->assertOk();

    MasterRegistry::all()->each(function ($resource): void {
        $this->get(route('masters.index', ['resource' => $resource->slug()]))->assertOk();
        $this->get(route('masters.create', ['resource' => $resource->slug()]))->assertOk();
    });
});
