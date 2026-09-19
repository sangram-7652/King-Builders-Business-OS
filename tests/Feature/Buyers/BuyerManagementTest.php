<?php

declare(strict_types=1);

use App\Actions\Buyers\CreateBuyer;
use App\Enums\BuyerStatus;
use App\Exceptions\DomainException;
use App\Livewire\Buyers\BuyerForm;
use App\Livewire\Buyers\BuyerIndex;
use App\Models\Buyer;
use App\Models\Masters\City;
use App\Models\Masters\State;
use App\Models\User;
use App\Support\Sequences\SequenceGenerator;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(fn () => seedRbac());

it('creates a buyer with a generated customer code', function () {
    $state = State::factory()->create();
    $city = City::factory()->create(['state_id' => $state->id]);

    Livewire::actingAs(buyerManager())
        ->test(BuyerForm::class)
        ->set('first_name', 'Anita')
        ->set('last_name', 'Sharma')
        ->set('phone', '98765 43210')
        ->set('state_id', (string) $state->id)
        ->set('city_id', (string) $city->id)
        ->call('save')
        ->assertHasNoErrors();

    $buyer = Buyer::firstWhere('first_name', 'Anita');
    expect($buyer)->not->toBeNull()
        ->and($buyer->customer_code)->toMatch('/^BUY-\d{6}$/')
        ->and($buyer->status)->toBe(BuyerStatus::Active)
        ->and($buyer->created_by)->not->toBeNull();
});

it('generates sequential, unique customer codes', function () {
    $seq = app(SequenceGenerator::class);

    $codes = collect(range(1, 5))->map(function () use ($seq) {
        return DB::transaction(fn () => Buyer::formatCode($seq->next(Buyer::SEQUENCE_KEY)));
    });

    expect($codes->all())->toBe(['BUY-000001', 'BUY-000002', 'BUY-000003', 'BUY-000004', 'BUY-000005'])
        ->and($codes->unique())->toHaveCount(5);
});

it('enforces the unique customer_code constraint', function () {
    Buyer::factory()->create(['customer_code' => 'BUY-000042']);

    expect(fn () => Buyer::factory()->create(['customer_code' => 'BUY-000042']))
        ->toThrow(QueryException::class);
});

it('resolves state / city relationships and validates the pairing', function () {
    $stateA = State::factory()->create();
    $stateB = State::factory()->create();
    $cityInB = City::factory()->create(['state_id' => $stateB->id]);

    // Direct action guard
    expect(fn () => app(CreateBuyer::class)->handle([
        'first_name' => 'X', 'phone' => '9999999999',
        'state_id' => $stateA->id, 'city_id' => $cityInB->id,
        'middle_name' => null, 'last_name' => null, 'alternate_phone' => null, 'email' => null,
        'date_of_birth' => null, 'gender' => null, 'occupation' => null, 'address' => null,
        'pincode' => null, 'pan_number' => null, 'aadhaar_number' => null,
    ], User::factory()->create()))->toThrow(DomainException::class, 'does not belong');

    // Form validation
    Livewire::actingAs(buyerManager())
        ->test(BuyerForm::class)
        ->set('first_name', 'Mismatch')
        ->set('phone', '9876543210')
        ->set('state_id', (string) $stateA->id)
        ->set('city_id', (string) $cityInB->id)
        ->call('save')
        ->assertHasErrors('city_id');
});

it('updates a buyer', function () {
    $buyer = Buyer::factory()->create(['first_name' => 'Old']);

    Livewire::actingAs(buyerManager())
        ->test(BuyerForm::class, ['buyer' => $buyer])
        ->set('first_name', 'New')
        ->call('save')
        ->assertHasNoErrors();

    expect($buyer->fresh()->first_name)->toBe('New');
});

it('archives and restores a buyer via status transitions', function () {
    $buyer = Buyer::factory()->create(['status' => BuyerStatus::Active]);

    Livewire::actingAs(buyerManager())
        ->test(BuyerIndex::class)
        ->call('setStatus', $buyer->id, 'archived');
    expect($buyer->fresh()->status)->toBe(BuyerStatus::Archived);

    Livewire::actingAs(buyerManager())
        ->test(BuyerIndex::class)
        ->call('setStatus', $buyer->id, 'active');
    expect($buyer->fresh()->status)->toBe(BuyerStatus::Active);
});

it('phone is not unique — households may share a landline / number', function () {
    Buyer::factory()->create(['phone' => '9876543210', 'email' => 'a@example.com']);
    Buyer::factory()->create(['phone' => '9876543210', 'email' => 'b@example.com']);

    expect(Buyer::where('phone', '9876543210')->count())->toBe(2);
});

it('email IS unique among live buyers — it is the portal login identity (F-M5-1)', function () {
    Buyer::factory()->create(['email' => 'family@example.com']);

    expect(fn () => Buyer::factory()->create(['email' => 'FAMILY@example.com'])) // also case-normalised
        ->toThrow(QueryException::class);
});

it('a soft-deleted buyer does not block re-registering the same email', function () {
    $old = Buyer::factory()->create(['email' => 'reuse@example.com']);
    $old->delete();

    $new = Buyer::factory()->create(['email' => 'reuse@example.com']);

    expect($new->exists)->toBeTrue()
        ->and(Buyer::where('email', 'reuse@example.com')->count())->toBe(1);
});

// ---------------------------------------------------------------------------
// F-BUY-1 — the create form validates email uniqueness before insert instead
// of letting the buyers_email_canonical_unique constraint 500 the request.
// ---------------------------------------------------------------------------

it('creates a buyer with a unique email successfully', function () {
    Livewire::actingAs(buyerManager())
        ->test(BuyerForm::class)
        ->set('first_name', 'Priya')
        ->set('phone', '9876500001')
        ->set('email', 'priya@example.com')
        ->call('save')
        ->assertHasNoErrors();

    $buyer = Buyer::firstWhere('first_name', 'Priya');
    expect($buyer)->not->toBeNull()
        ->and($buyer->email)->toBe('priya@example.com');
});

it('rejects creating a buyer with a duplicate email — a form error, never a 500', function () {
    Buyer::factory()->create(['email' => 'sangramsingh.dev@gmail.com']);

    $component = Livewire::actingAs(buyerManager())
        ->test(BuyerForm::class)
        ->set('first_name', 'Duplicate')
        ->set('phone', '9876500002')
        ->set('email', 'sangramsingh.dev@gmail.com')
        ->call('save');

    $component->assertHasErrors(['email' => 'unique']);
    expect($component->errors()->first('email'))->toBe('The email has already been taken.');

    expect(Buyer::where('email', 'sangramsingh.dev@gmail.com')->count())->toBe(1);
});

it('rejects a duplicate email on create case-insensitively and after trimming', function () {
    Buyer::factory()->create(['email' => 'family@example.com']);

    Livewire::actingAs(buyerManager())
        ->test(BuyerForm::class)
        ->set('first_name', 'Case')
        ->set('phone', '9876500003')
        ->set('email', '  FAMILY@Example.com  ')
        ->call('save')
        ->assertHasErrors(['email' => 'unique']);

    expect(Buyer::where('email', 'family@example.com')->count())->toBe(1);
});

it('does not block creating a buyer whose email belonged to a soft-deleted buyer', function () {
    $old = Buyer::factory()->create(['email' => 'freed@example.com']);
    $old->delete();

    Livewire::actingAs(buyerManager())
        ->test(BuyerForm::class)
        ->set('first_name', 'Fresh')
        ->set('phone', '9876500004')
        ->set('email', 'freed@example.com')
        ->call('save')
        ->assertHasNoErrors();

    expect(Buyer::where('email', 'freed@example.com')->count())->toBe(1);
});

it('converts a duplicate-email database race into the same friendly form error instead of a 500', function () {
    // Editing intentionally skips the pre-insert uniqueness check (F-BUY-1
    // only guards create — see BuyerForm::rules()), so saving an edit whose
    // email now collides with another buyer reaches the database constraint
    // directly. This is exactly the defensive path a genuine concurrent
    // create-create race would also hit — same exception, same catch block.
    Buyer::factory()->create(['email' => 'taken@example.com']);
    $editing = Buyer::factory()->create(['email' => 'mine@example.com']);

    $component = Livewire::actingAs(buyerManager())
        ->test(BuyerForm::class, ['buyer' => $editing])
        ->set('email', 'taken@example.com')
        ->call('save');

    $component->assertHasErrors('email');
    expect($component->errors()->first('email'))->toBe('The email has already been taken.');

    expect($editing->fresh()->email)->toBe('mine@example.com'); // unchanged
});
