<?php

declare(strict_types=1);

use App\Livewire\Masters\MasterForm;
use App\Livewire\Masters\MasterIndex;
use App\Models\Masters\Bank;
use App\Models\Masters\PlotCategory;
use App\Models\Masters\State;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(fn () => seedRbac());

it('creates a master through the generic form', function () {
    Livewire::actingAs(masterAdmin())
        ->test(MasterForm::class, ['resource' => 'plot-categories'])
        ->set('form.name', 'Villa')
        ->set('form.code', 'VILLA')
        ->set('form.description', 'Independent villas')
        ->set('form.sort_order', 3)
        ->set('form.is_active', true)
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('masters.index', ['resource' => 'plot-categories']));

    $category = PlotCategory::firstWhere('code', 'VILLA');
    expect($category)->not->toBeNull()
        ->and($category->name)->toBe('Villa')
        ->and($category->is_active)->toBeTrue();
});

it('updates a master through the generic form', function () {
    $bank = Bank::factory()->create(['name' => 'Old Name', 'code' => 'OLD']);

    Livewire::actingAs(masterAdmin())
        ->test(MasterForm::class, ['resource' => 'banks', 'record' => $bank->id])
        ->assertSet('form.name', 'Old Name')
        ->set('form.name', 'New Name')
        ->call('save')
        ->assertHasNoErrors();

    expect($bank->fresh()->name)->toBe('New Name');
});

it('activates and deactivates a master from the list', function () {
    $state = State::factory()->create(['is_active' => true]);

    $component = Livewire::actingAs(masterAdmin())
        ->test(MasterIndex::class, ['resource' => 'states']);

    $component->call('toggleStatus', $state->id);
    expect($state->fresh()->is_active)->toBeFalse();

    $component->call('toggleStatus', $state->id);
    expect($state->fresh()->is_active)->toBeTrue();
});

it('soft-deletes an unreferenced master from the list', function () {
    $category = PlotCategory::factory()->create();

    Livewire::actingAs(masterAdmin())
        ->test(MasterIndex::class, ['resource' => 'plot-categories'])
        ->call('delete', $category->id);

    expect(PlotCategory::find($category->id))->toBeNull()
        ->and(PlotCategory::withTrashed()->find($category->id))->not->toBeNull();
});

it('searches and filters the list', function () {
    PlotCategory::factory()->create(['name' => 'Residential', 'code' => 'RESI', 'is_active' => true]);
    PlotCategory::factory()->create(['name' => 'Commercial', 'code' => 'COMM', 'is_active' => false]);

    Livewire::actingAs(masterAdmin())
        ->test(MasterIndex::class, ['resource' => 'plot-categories'])
        ->set('search', 'resi')
        ->assertSee('Residential')
        ->assertDontSee('Commercial')
        ->set('search', '')
        ->set('status', 'inactive')
        ->assertSee('Commercial')
        ->assertDontSee('Residential');
});

it('filters bank branches by their parent bank', function () {
    $hdfc = Bank::factory()->create(['name' => 'HDFC']);
    $sbi = Bank::factory()->create(['name' => 'SBI']);
    $hdfc->branches()->create(['name' => 'HDFC Andheri', 'is_active' => true, 'sort_order' => 0]);
    $sbi->branches()->create(['name' => 'SBI Bandra', 'is_active' => true, 'sort_order' => 0]);

    Livewire::actingAs(masterAdmin())
        ->test(MasterIndex::class, ['resource' => 'bank-branches'])
        ->set('filter.bank_id', $hdfc->id)
        ->assertSee('HDFC Andheri')
        ->assertDontSee('SBI Bandra');
});
