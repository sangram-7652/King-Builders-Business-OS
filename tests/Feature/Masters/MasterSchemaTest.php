<?php

declare(strict_types=1);

use App\Masters\MasterRegistry;
use App\Models\Masters\Bank;
use App\Models\Masters\BankBranch;
use App\Models\Masters\City;
use App\Models\Masters\PlotCategory;
use App\Models\Masters\State;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('creates all 16 master tables with the standard columns', function () {
    $tables = [
        'plot_categories', 'plot_sizes', 'plot_dimensions', 'plc_types',
        'lead_sources',
        'tds_rules', 'interest_rules', 'payment_types', 'payment_modes',
        'banks', 'bank_branches', 'states', 'cities',
        'document_types', 'cancellation_reasons', 'transfer_reasons',
    ];

    foreach ($tables as $table) {
        expect(Schema::hasTable($table))->toBeTrue("missing table {$table}");
        expect(Schema::hasColumns($table, ['is_active', 'sort_order', 'created_at', 'updated_at', 'deleted_at']))
            ->toBeTrue("{$table} is missing a standard master column");
    }
});

it('registers exactly 16 master resources, each mapping to a real model', function () {
    $resources = MasterRegistry::all();

    expect($resources)->toHaveCount(16);

    $resources->each(function ($resource): void {
        expect(class_exists($resource->model()))->toBeTrue()
            ->and($resource->slug())->toMatch('/^[a-z-]+$/');
    });
});

it('enforces the unique code on plot categories', function () {
    PlotCategory::factory()->create(['code' => 'RESI']);

    expect(fn () => PlotCategory::factory()->create(['code' => 'RESI']))
        ->toThrow(QueryException::class);
});

it('enforces the composite unique (state_id, name) on cities', function () {
    $state = State::factory()->create();
    City::factory()->create(['state_id' => $state->id, 'name' => 'Springfield']);

    expect(fn () => City::factory()->create(['state_id' => $state->id, 'name' => 'Springfield']))
        ->toThrow(QueryException::class);

    // same name, different state is fine
    $other = State::factory()->create();
    City::factory()->create(['state_id' => $other->id, 'name' => 'Springfield']);
    expect(City::where('name', 'Springfield')->count())->toBe(2);
});

it('enforces the unique IFSC on bank branches', function () {
    $bank = Bank::factory()->create();
    BankBranch::factory()->create(['bank_id' => $bank->id, 'ifsc' => 'HDFC0001234']);

    expect(fn () => BankBranch::factory()->create(['bank_id' => $bank->id, 'ifsc' => 'HDFC0001234']))
        ->toThrow(QueryException::class);
});

it('restricts hard-deleting a state that still has cities', function () {
    $state = State::factory()->create();
    City::factory()->create(['state_id' => $state->id]);

    expect(fn () => $state->forceDelete())->toThrow(QueryException::class);
});

it('soft deletes master rows so historical references stay resolvable', function () {
    $category = PlotCategory::factory()->create();
    $id = $category->id;

    $category->delete();

    expect(PlotCategory::find($id))->toBeNull()
        ->and(PlotCategory::withTrashed()->find($id))->not->toBeNull();
});
