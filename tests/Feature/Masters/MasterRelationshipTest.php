<?php

declare(strict_types=1);

use App\Actions\Masters\DeleteMaster;
use App\Exceptions\DomainException;
use App\Models\Masters\Bank;
use App\Models\Masters\BankBranch;
use App\Models\Masters\City;
use App\Models\Masters\PaymentType;
use App\Models\Masters\State;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('resolves the state -> cities relationship both ways', function () {
    $state = State::factory()->create(['name' => 'Haryana']);
    $gurugram = City::factory()->create(['state_id' => $state->id, 'name' => 'Gurugram']);

    expect($state->cities)->toHaveCount(1)
        ->and($state->cities->first()->is($gurugram))->toBeTrue()
        ->and($gurugram->state->is($state))->toBeTrue();
});

it('resolves the bank -> branches relationship both ways', function () {
    $bank = Bank::factory()->create(['name' => 'HDFC']);
    $branch = BankBranch::factory()->create(['bank_id' => $bank->id, 'name' => 'Andheri']);

    expect($bank->branches)->toHaveCount(1)
        ->and($branch->bank->is($bank))->toBeTrue();
});

it('refuses to delete a master that is referenced by child data', function () {
    $state = State::factory()->create();
    City::factory()->create(['state_id' => $state->id]);

    expect(fn () => app(DeleteMaster::class)->handle($state))
        ->toThrow(DomainException::class, 'in use');

    expect(State::find($state->id))->not->toBeNull();
});

it('deletes a master once its children are gone', function () {
    $bank = Bank::factory()->create();
    $branch = BankBranch::factory()->create(['bank_id' => $bank->id]);

    $branch->delete();

    app(DeleteMaster::class)->handle($bank);
    expect(Bank::find($bank->id))->toBeNull();
});

it('refuses to delete a system row', function () {
    $type = PaymentType::factory()->system()->create();

    expect(fn () => app(DeleteMaster::class)->handle($type))
        ->toThrow(DomainException::class, 'system-defined');

    expect(PaymentType::find($type->id))->not->toBeNull();
});
