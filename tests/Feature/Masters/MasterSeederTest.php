<?php

declare(strict_types=1);

use App\Models\Masters\Bank;
use App\Models\Masters\BankBranch;
use App\Models\Masters\City;
use App\Models\Masters\PaymentMode;
use App\Models\Masters\PaymentType;
use App\Models\Masters\PlotCategory;
use App\Models\Masters\State;
use Database\Seeders\Masters\MasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('seeds sensible initial master values', function () {
    $this->seed(MasterDataSeeder::class);

    expect(PlotCategory::count())->toBeGreaterThanOrEqual(4)
        ->and(State::count())->toBeGreaterThanOrEqual(28)
        ->and(City::count())->toBeGreaterThan(0)
        ->and(Bank::count())->toBeGreaterThanOrEqual(5)
        ->and(BankBranch::count())->toBeGreaterThan(0);

    // The prompt's canonical payment modes / types are all present.
    foreach (['Cash', 'Cheque', 'RTGS', 'NEFT', 'UPI', 'Bank Transfer', 'Online'] as $mode) {
        expect(PaymentMode::where('name', $mode)->exists())->toBeTrue("missing payment mode {$mode}");
    }
    foreach (['Booking Amount', 'Installment', 'Down Payment', 'Registry Payment', 'Other Charges', 'Refund', 'Adjustment'] as $type) {
        expect(PaymentType::where('name', $type)->exists())->toBeTrue("missing payment type {$type}");
    }
});

it('seeds reference data as system rows', function () {
    $this->seed(MasterDataSeeder::class);

    expect(PaymentMode::where('name', 'Cash')->first()->isSystem())->toBeTrue()
        ->and(PaymentType::where('name', 'Refund')->first()->isSystem())->toBeTrue();
});

it('is idempotent — re-running changes nothing', function () {
    $this->seed(MasterDataSeeder::class);

    $before = collect([PlotCategory::class, State::class, City::class, Bank::class, PaymentMode::class])
        ->mapWithKeys(fn ($m) => [$m => $m::count()]);

    $this->seed(MasterDataSeeder::class);

    $after = collect([PlotCategory::class, State::class, City::class, Bank::class, PaymentMode::class])
        ->mapWithKeys(fn ($m) => [$m => $m::count()]);

    expect($after->all())->toBe($before->all());
});

it('links seeded cities to seeded states', function () {
    $this->seed(MasterDataSeeder::class);

    $haryana = State::where('code', 'HR')->first();
    expect($haryana)->not->toBeNull()
        ->and($haryana->cities()->where('name', 'Gurugram')->exists())->toBeTrue();
});

it('links seeded branches to seeded banks', function () {
    $this->seed(MasterDataSeeder::class);

    $branch = BankBranch::where('ifsc', 'HDFC0000001')->first();
    expect($branch)->not->toBeNull()
        ->and($branch->bank->code)->toBe('HDFC');
});
