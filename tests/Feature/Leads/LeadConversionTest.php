<?php

declare(strict_types=1);

use App\Actions\Leads\ConvertLeadToBuyer;
use App\Enums\LeadActivityType;
use App\Enums\LeadStatus;
use App\Exceptions\DomainException;
use App\Livewire\Leads\LeadConvert;
use App\Models\Buyer;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(fn () => seedRbac());

function newBuyerPayload(array $overrides = []): array
{
    return array_merge([
        'first_name' => 'Ravi', 'middle_name' => null, 'last_name' => 'Kumar',
        'phone' => '9876543210', 'alternate_phone' => null, 'email' => null,
        'date_of_birth' => null, 'gender' => null, 'occupation' => null, 'address' => null,
        'state_id' => null, 'city_id' => null, 'pincode' => null,
        'pan_number' => null, 'aadhaar_number' => null,
    ], $overrides);
}

it('converts a qualified lead: creates a buyer, links it, marks CONVERTED with metadata', function () {
    $actor = User::factory()->create();
    $lead = Lead::factory()->qualified()->create(['phone' => '9876543210']);

    $buyer = app(ConvertLeadToBuyer::class)->handle($lead, $actor, newBuyerData: newBuyerPayload());

    $lead->refresh();
    expect($buyer)->toBeInstanceOf(Buyer::class)
        ->and($buyer->customer_code)->toMatch('/^BUY-\d{6}$/')
        ->and($lead->status)->toBe(LeadStatus::Converted)
        ->and($lead->buyer_id)->toBe($buyer->id)
        ->and($lead->converted_at)->not->toBeNull()
        ->and($lead->converted_by)->toBe($actor->id)
        ->and($lead->activities()->where('type', LeadActivityType::Converted->value)->exists())->toBeTrue();
});

it('refuses to convert a lead that is not qualified', function () {
    $lead = Lead::factory()->status(LeadStatus::Contacted)->create();

    expect(fn () => app(ConvertLeadToBuyer::class)->handle($lead, User::factory()->create(), newBuyerData: newBuyerPayload()))
        ->toThrow(DomainException::class, 'qualified');

    expect($lead->fresh()->status)->toBe(LeadStatus::Contacted)
        ->and(Buyer::count())->toBe(0);
});

it('is idempotent — an already-converted lead returns its buyer without side effects', function () {
    $actor = User::factory()->create();
    $lead = Lead::factory()->qualified()->create();

    $first = app(ConvertLeadToBuyer::class)->handle($lead, $actor, newBuyerData: newBuyerPayload());
    $convertedAt = $lead->fresh()->converted_at;

    $second = app(ConvertLeadToBuyer::class)->handle($lead->fresh(), $actor, newBuyerData: newBuyerPayload(['first_name' => 'Different']));

    expect($second->id)->toBe($first->id)
        ->and(Buyer::count())->toBe(1)
        ->and($lead->fresh()->converted_at->eq($convertedAt))->toBeTrue()
        ->and($lead->fresh()->activities()->where('type', LeadActivityType::Converted->value)->count())->toBe(1);
});

it('converts against an explicitly selected existing buyer', function () {
    $actor = User::factory()->create();
    $existing = Buyer::factory()->create(['phone' => '9876543210']);
    $lead = Lead::factory()->qualified()->create(['phone' => '9876543210']);

    $buyer = app(ConvertLeadToBuyer::class)->handle($lead, $actor, existingBuyerId: $existing->id);

    expect($buyer->id)->toBe($existing->id)
        ->and(Buyer::count())->toBe(1)
        ->and($lead->fresh()->buyer_id)->toBe($existing->id);
});

it('rolls back everything — including the code sequence — when buyer creation fails', function () {
    $actor = User::factory()->create();
    $lead = Lead::factory()->qualified()->create();

    // Pre-occupy the customer code the sequence is about to hand out, so the
    // INSERT hits the unique constraint after the sequence has been bumped.
    Buyer::factory()->create(['customer_code' => 'BUY-000001']);
    DB::table('code_sequences')->updateOrInsert(['key' => 'buyer'], ['next_value' => 1, 'updated_at' => now(), 'created_at' => now()]);

    expect(fn () => app(ConvertLeadToBuyer::class)->handle($lead, $actor, newBuyerData: newBuyerPayload()))
        ->toThrow(QueryException::class);

    $lead->refresh();
    expect($lead->status)->toBe(LeadStatus::Qualified)
        ->and($lead->buyer_id)->toBeNull()
        ->and(Buyer::count())->toBe(1)                                             // only the pre-existing buyer
        ->and((int) DB::table('code_sequences')->where('key', 'buyer')->value('next_value'))->toBe(1) // rolled back
        ->and($lead->activities()->where('type', LeadActivityType::Converted->value)->exists())->toBeFalse();
});

it('shows existing-buyer options and converts through the Livewire screen', function () {
    $manager = leadManager();
    $existing = Buyer::factory()->create(['phone' => '9876543210', 'first_name' => 'Existing', 'last_name' => 'Person']);
    $lead = Lead::factory()->qualified()->create(['phone' => '9876543210', 'assigned_to' => $manager->id]);

    Livewire::actingAs($manager)
        ->test(LeadConvert::class, ['lead' => $lead])
        ->assertSee('Existing buyer found')
        ->assertSee($existing->customer_code)
        ->call('useExisting', $existing->id)
        ->call('convert')
        ->assertHasNoErrors()
        ->assertRedirect(route('buyers.show', $existing));

    expect($lead->fresh()->buyer_id)->toBe($existing->id)
        ->and(Buyer::count())->toBe(1);
});

it('the convert screen bounces an already-converted lead to its buyer', function () {
    $manager = leadManager();
    $buyer = Buyer::factory()->create();
    $lead = Lead::factory()->status(LeadStatus::Converted)->create([
        'buyer_id' => $buyer->id, 'assigned_to' => $manager->id,
    ]);

    Livewire::actingAs($manager)
        ->test(LeadConvert::class, ['lead' => $lead])
        ->assertRedirect(route('buyers.show', $buyer));
});
