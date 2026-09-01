<?php

declare(strict_types=1);

use App\Enums\Masters\PlcCalculationType;
use App\Livewire\Masters\MasterForm;
use App\Models\Masters\Bank;
use App\Models\Masters\PlotCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(fn () => seedRbac());

function submitMaster(string $resource, array $state, ?int $record = null)
{
    $component = Livewire::actingAs(masterAdmin())->test(MasterForm::class, array_filter([
        'resource' => $resource,
        'record' => $record,
    ], fn ($v) => $v !== null));

    foreach ($state as $key => $value) {
        $component->set("form.{$key}", $value);
    }

    return $component->call('save');
}

it('requires the mandatory fields', function () {
    submitMaster('plot-categories', ['name' => '', 'code' => ''])
        ->assertHasErrors(['name', 'code']);
});

it('rejects a duplicate code', function () {
    PlotCategory::factory()->create(['code' => 'RESI']);

    submitMaster('plot-categories', ['name' => 'Another', 'code' => 'RESI'])
        ->assertHasErrors('code');
});

it('validates a TDS percentage is between 0 and 100', function () {
    submitMaster('tds-rules', ['name' => 'Bad', 'percentage' => 150, 'applicable_from' => '2024-01-01'])
        ->assertHasErrors('percentage');

    submitMaster('tds-rules', ['name' => 'Good', 'percentage' => 1.5, 'applicable_from' => '2024-01-01'])
        ->assertHasNoErrors();
});

it('validates the TDS applicable date range', function () {
    submitMaster('tds-rules', [
        'name' => 'Range', 'percentage' => 1,
        'applicable_from' => '2024-06-01', 'applicable_until' => '2024-01-01',
    ])->assertHasErrors('applicable_until');
});

it('caps a percentage PLC value at 100 but allows large fixed amounts', function () {
    submitMaster('plc-types', [
        'name' => 'Corner', 'code' => 'CORNER',
        'calculation_type' => PlcCalculationType::Percentage->value, 'value' => 250,
    ])->assertHasErrors('value');

    submitMaster('plc-types', [
        'name' => 'Preferred block', 'code' => 'BLOCK',
        'calculation_type' => PlcCalculationType::FixedAmount->value, 'value' => 500000,
    ])->assertHasNoErrors();
});

it('rejects an invalid PLC calculation type', function () {
    submitMaster('plc-types', ['name' => 'X', 'code' => 'X', 'calculation_type' => 'nonsense', 'value' => 1])
        ->assertHasErrors('calculation_type');
});

it('validates interest rule rate, grace period and dates', function () {
    submitMaster('interest-rules', [
        'name' => 'Bad rate', 'interest_type' => 'simple', 'rate' => 500,
        'frequency' => 'monthly', 'grace_period_days' => -5,
        'effective_from' => '2024-01-01', 'effective_until' => '2023-01-01',
    ])->assertHasErrors(['rate', 'grace_period_days', 'effective_until']);

    submitMaster('interest-rules', [
        'name' => 'Good', 'interest_type' => 'compound', 'rate' => 12.5,
        'frequency' => 'quarterly', 'grace_period_days' => 10,
        'effective_from' => '2024-01-01',
    ])->assertHasNoErrors();
});

it('validates IFSC format and uniqueness on bank branches', function () {
    $bank = Bank::factory()->create();

    submitMaster('bank-branches', ['bank_id' => $bank->id, 'name' => 'Bad IFSC', 'ifsc' => 'nope'])
        ->assertHasErrors('ifsc');

    submitMaster('bank-branches', ['bank_id' => $bank->id, 'name' => 'Good', 'ifsc' => 'HDFC0001234'])
        ->assertHasNoErrors();

    submitMaster('bank-branches', ['bank_id' => $bank->id, 'name' => 'Dup', 'ifsc' => 'HDFC0001234'])
        ->assertHasErrors('ifsc');
});

it('allows blank IFSC (nullable)', function () {
    $bank = Bank::factory()->create();

    submitMaster('bank-branches', ['bank_id' => $bank->id, 'name' => 'No IFSC', 'ifsc' => ''])
        ->assertHasNoErrors();
});
