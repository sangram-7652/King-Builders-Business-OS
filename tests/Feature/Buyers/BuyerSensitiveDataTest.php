<?php

declare(strict_types=1);

use App\Actions\Buyers\CreateBuyer;
use App\Livewire\Buyers\BuyerShow;
use App\Models\Buyer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(fn () => seedRbac());

it('stores PAN and Aadhaar encrypted at rest', function () {
    $buyer = Buyer::factory()->create([
        'pan_number' => 'ABCDE1234F',
        'aadhaar_number' => '123412341234',
    ]);

    $raw = DB::table('buyers')->where('id', $buyer->id)->first();

    expect($raw->pan_number)->not->toContain('ABCDE1234F')
        ->and($raw->aadhaar_number)->not->toContain('123412341234')
        ->and(strlen($raw->pan_number))->toBeGreaterThan(20) // ciphertext
        ->and($buyer->fresh()->pan_number)->toBe('ABCDE1234F'); // decrypts for the app
});

it('never serialises the sensitive fields', function () {
    $buyer = Buyer::factory()->withSensitiveData()->create();

    $array = $buyer->fresh()->toArray();

    expect($array)->not->toHaveKey('pan_number')
        ->and($array)->not->toHaveKey('aadhaar_number');
});

it('masks the identifiers by default and reveals only with buyers.documents', function () {
    $buyer = Buyer::factory()->create(['pan_number' => 'ABCDE1234F', 'aadhaar_number' => '123412341234']);

    // A user with buyers.view but NOT buyers.documents
    $viewer = makeUser(permissions: ['buyers.view']);

    Livewire::actingAs($viewer)
        ->test(BuyerShow::class, ['buyer' => $buyer])
        ->assertSee('••••••234F')          // masked PAN
        ->assertDontSee('ABCDE1234F')
        ->call('toggleReveal')
        ->assertForbidden();               // cannot reveal

    // A user WITH buyers.documents
    $kyc = makeUser(permissions: ['buyers.view', 'buyers.documents']);

    Livewire::actingAs($kyc)
        ->test(BuyerShow::class, ['buyer' => $buyer])
        ->assertSee('••••••234F')
        ->assertDontSee('ABCDE1234F')
        ->call('toggleReveal')
        ->assertSee('ABCDE1234F');          // revealed
});

it('masks a buyer with no identifiers gracefully', function () {
    $buyer = Buyer::factory()->create(['pan_number' => null, 'aadhaar_number' => null]);

    Livewire::actingAs(makeUser(permissions: ['buyers.view', 'buyers.documents']))
        ->test(BuyerShow::class, ['buyer' => $buyer])
        ->assertSee('No KYC identifiers on record');
});

it('does not expose sensitive values in logs when a buyer is created', function () {
    $tmp = tempnam(sys_get_temp_dir(), 'log');
    config(['logging.channels.testfile' => ['driver' => 'single', 'path' => $tmp, 'level' => 'debug']]);
    config(['logging.default' => 'testfile']);

    app(CreateBuyer::class)->handle([
        'first_name' => 'Log', 'phone' => '9999999999',
        'pan_number' => 'ZZZZZ9999Z', 'aadhaar_number' => '999988887777',
        'middle_name' => null, 'last_name' => null, 'alternate_phone' => null, 'email' => null,
        'date_of_birth' => null, 'gender' => null, 'occupation' => null, 'address' => null,
        'state_id' => null, 'city_id' => null, 'pincode' => null,
    ], User::factory()->create());

    $contents = file_get_contents($tmp);
    expect($contents)->toContain('buyer.created')
        ->and($contents)->not->toContain('ZZZZZ9999Z')
        ->and($contents)->not->toContain('999988887777');

    @unlink($tmp);
});
