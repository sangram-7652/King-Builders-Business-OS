<?php

declare(strict_types=1);

use App\Actions\Partners\ChangePartnerStatusAction;
use App\Actions\Partners\CreatePartnerAction;
use App\Actions\Partners\UpdatePartnerAction;
use App\Enums\PartnerActivityType;
use App\Enums\PartnerStatus;
use App\Enums\PartnerType;
use App\Exceptions\DomainException;
use App\Models\Partner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(fn () => seedRbac());

/** @return array{0: array<string,mixed>, 1: User} */
function partnerPayload(array $overrides = []): array
{
    return array_merge([
        'type' => 'individual',
        'name' => 'Priya Broker',
        'company_name' => null,
        'contact_person' => null,
        'phone' => '9711111111',
        'alternate_phone' => null,
        'email' => 'priya@example.com',
        'address' => null,
        'state_id' => null,
        'city_id' => null,
        'pincode' => null,
        'pan_number' => 'ABCDE1234F',
        'rera_number' => null,
        'bank_account_name' => null,
        'bank_account_number' => null,
        'bank_ifsc' => null,
        'bank_name' => null,
        'notes' => null,
    ], $overrides);
}

it('creates a partner with a sequential code, draft status and a created activity', function () {
    $actor = User::factory()->create();

    $a = app(CreatePartnerAction::class)->handle(partnerPayload(), $actor);
    $b = app(CreatePartnerAction::class)->handle(partnerPayload(['name' => 'Second']), $actor);

    expect($a->partner_code)->toStartWith('PTNR-')
        ->and($b->partner_code)->not->toBe($a->partner_code)
        ->and($a->status)->toBe(PartnerStatus::Draft)
        ->and($a->created_by)->toBe($actor->id)
        ->and($a->onboarded_at)->not->toBeNull()
        ->and($a->activities()->where('type', PartnerActivityType::Created->value)->exists())->toBeTrue();
});

it('stores PAN encrypted and never exposes it in array form', function () {
    $partner = app(CreatePartnerAction::class)->handle(partnerPayload(), User::factory()->create());

    $raw = DB::table('partners')->where('id', $partner->id)->value('pan_number');

    expect($raw)->not->toBe('ABCDE1234F')
        ->and($partner->pan_number)->toBe('ABCDE1234F')
        ->and($partner->toArray())->not->toHaveKey('pan_number')
        ->and($partner->maskedPan())->toBe('••••••234F');
});

it('records a bank-details activity only when the bank fields actually change', function () {
    $actor = User::factory()->create();
    $partner = app(CreatePartnerAction::class)->handle(partnerPayload(), $actor);

    app(UpdatePartnerAction::class)->handle($partner, partnerPayload(['name' => 'Renamed']), $actor);
    expect($partner->fresh()->activities()->where('type', PartnerActivityType::BankDetailsUpdated->value)->exists())->toBeFalse();

    app(UpdatePartnerAction::class)->handle($partner->fresh(), partnerPayload([
        'name' => 'Renamed', 'bank_account_number' => '11223344556677', 'bank_ifsc' => 'ABCD0123456',
    ]), $actor);

    expect($partner->fresh()->activities()->where('type', PartnerActivityType::BankDetailsUpdated->value)->exists())->toBeTrue()
        ->and($partner->fresh()->bank_account_number)->toBe('11223344556677');
});

it('walks the partner lifecycle through the transition map', function () {
    $actor = User::factory()->create();
    $partner = app(CreatePartnerAction::class)->handle(partnerPayload(), $actor);

    foreach ([PartnerStatus::Pending, PartnerStatus::Active, PartnerStatus::OnHold, PartnerStatus::Active, PartnerStatus::Suspended, PartnerStatus::Inactive] as $target) {
        app(ChangePartnerStatusAction::class)->handle($partner->fresh(), $target, $actor);
    }

    expect($partner->fresh()->status)->toBe(PartnerStatus::Inactive);
});

it('stamps approval fields the first time a partner reaches active', function () {
    $actor = User::factory()->create();
    $partner = app(CreatePartnerAction::class)->handle(partnerPayload(), $actor);

    app(ChangePartnerStatusAction::class)->handle($partner, PartnerStatus::Active, $actor);

    $fresh = $partner->fresh();
    expect($fresh->approved_at)->not->toBeNull()
        ->and($fresh->approved_by)->toBe($actor->id)
        ->and($fresh->activities()->where('type', PartnerActivityType::Approved->value)->exists())->toBeTrue();

    // A later re-activation does not overwrite the original approval.
    app(ChangePartnerStatusAction::class)->handle($fresh, PartnerStatus::OnHold, $actor);
    $approvedAt = $fresh->approved_at;
    app(ChangePartnerStatusAction::class)->handle($partner->fresh(), PartnerStatus::Active, $actor);

    expect($partner->fresh()->approved_at->equalTo($approvedAt))->toBeTrue();
});

it('rejects a status jump that is not on the map', function () {
    $partner = Partner::factory()->status(PartnerStatus::Draft)->create();

    expect(fn () => app(ChangePartnerStatusAction::class)->handle($partner, PartnerStatus::Blacklisted, User::factory()->create()))
        ->toThrow(DomainException::class);
});

it('is idempotent when moved to the status it already holds', function () {
    $actor = User::factory()->create();
    $partner = Partner::factory()->status(PartnerStatus::Active)->create();

    app(ChangePartnerStatusAction::class)->handle($partner, PartnerStatus::Active, $actor);

    expect($partner->fresh()->activities()->count())->toBe(0);
});

it('only allows an active partner to receive attribution', function () {
    expect(Partner::factory()->status(PartnerStatus::Active)->create()->canReceiveAttribution())->toBeTrue()
        ->and(Partner::factory()->status(PartnerStatus::OnHold)->create()->canReceiveAttribution())->toBeFalse()
        ->and(Partner::factory()->status(PartnerStatus::Blacklisted)->create()->canReceiveAttribution())->toBeFalse();
});

it('derives the display name from the company for a firm', function () {
    $firm = Partner::factory()->type(PartnerType::Firm)->create(['name' => 'John', 'company_name' => 'Acme Realty']);
    $indiv = Partner::factory()->type(PartnerType::Individual)->create(['name' => 'John', 'company_name' => null]);

    expect($firm->displayName())->toBe('Acme Realty')
        ->and($indiv->displayName())->toBe('John');
});
