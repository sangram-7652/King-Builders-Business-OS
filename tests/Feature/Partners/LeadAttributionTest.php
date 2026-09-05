<?php

declare(strict_types=1);

use App\Actions\Partners\AttributeLeadToPartner;
use App\Enums\LeadActivityType;
use App\Enums\PartnerActivityType;
use App\Enums\PartnerStatus;
use App\Exceptions\DomainException;
use App\Livewire\Leads\LeadShow;
use App\Models\Lead;
use App\Models\LeadPartnerAttribution;
use App\Models\Partner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(fn () => seedRbac());

it('attributes a lead to an active partner and opens one history span', function () {
    $actor = User::factory()->create();
    $lead = Lead::factory()->create();
    $partner = Partner::factory()->active()->create();

    app(AttributeLeadToPartner::class)->handle($lead, $partner, $actor);

    $fresh = $lead->fresh();
    expect($fresh->partner_id)->toBe($partner->id)
        ->and($fresh->partnerAttributions()->whereNull('ended_at')->count())->toBe(1)
        ->and($fresh->activities()->where('type', LeadActivityType::PartnerAttributed->value)->exists())->toBeTrue()
        ->and($partner->fresh()->activities()->where('type', PartnerActivityType::LeadAttributed->value)->exists())->toBeTrue();
});

it('closes the previous span and opens a new one on re-attribution — history is preserved', function () {
    $actor = User::factory()->create();
    $lead = Lead::factory()->create();
    $a = Partner::factory()->active()->create();
    $b = Partner::factory()->active()->create();

    app(AttributeLeadToPartner::class)->handle($lead, $a, $actor);
    app(AttributeLeadToPartner::class)->handle($lead->fresh(), $b, $actor);

    $spans = LeadPartnerAttribution::where('lead_id', $lead->id)->orderBy('id')->get();
    expect($spans)->toHaveCount(2)
        ->and($spans[0]->partner_id)->toBe($a->id)
        ->and($spans[0]->ended_at)->not->toBeNull()
        ->and($spans[1]->partner_id)->toBe($b->id)
        ->and($spans[1]->ended_at)->toBeNull()
        ->and($lead->fresh()->partner_id)->toBe($b->id)
        ->and($a->fresh()->activities()->where('type', PartnerActivityType::LeadAttributionRemoved->value)->exists())->toBeTrue();
});

it('records a direct-lead span when the partner is cleared', function () {
    $actor = User::factory()->create();
    $lead = Lead::factory()->create();
    $partner = Partner::factory()->active()->create();

    app(AttributeLeadToPartner::class)->handle($lead, $partner, $actor);
    app(AttributeLeadToPartner::class)->handle($lead->fresh(), null, $actor);

    expect($lead->fresh()->partner_id)->toBeNull()
        ->and(LeadPartnerAttribution::where('lead_id', $lead->id)->whereNull('ended_at')->first()->partner_id)->toBeNull()
        ->and($lead->fresh()->activities()->where('type', LeadActivityType::PartnerAttributionRemoved->value)->exists())->toBeTrue();
});

it('is a no-op when the same partner is re-attributed', function () {
    $actor = User::factory()->create();
    $lead = Lead::factory()->create();
    $partner = Partner::factory()->active()->create();

    app(AttributeLeadToPartner::class)->handle($lead, $partner, $actor);
    app(AttributeLeadToPartner::class)->handle($lead->fresh(), $partner, $actor);

    expect(LeadPartnerAttribution::where('lead_id', $lead->id)->count())->toBe(1);
});

it('refuses to attribute a lead to a non-active partner', function () {
    $lead = Lead::factory()->create();

    foreach ([PartnerStatus::Draft, PartnerStatus::OnHold, PartnerStatus::Blacklisted] as $status) {
        $partner = Partner::factory()->status($status)->create();
        expect(fn () => app(AttributeLeadToPartner::class)->handle($lead, $partner, User::factory()->create()))
            ->toThrow(DomainException::class);
    }
});

it('lets a sales user attribute a partner from the lead screen', function () {
    $user = makeUser(permissions: ['leads.view', 'leads.view_all', 'partners.attribute']);
    $lead = Lead::factory()->create(['assigned_to' => $user->id]);
    $partner = Partner::factory()->active()->create();

    Livewire::actingAs($user)
        ->test(LeadShow::class, ['lead' => $lead])
        ->call('openAttribute')
        ->set('attributePartnerId', (string) $partner->id)
        ->call('attributePartner')
        ->assertHasNoErrors();

    expect($lead->fresh()->partner_id)->toBe($partner->id);
});

it('stops a user without partners.attribute from attributing on the lead screen', function () {
    $user = makeUser(permissions: ['leads.view', 'leads.view_all']);
    $lead = Lead::factory()->create(['assigned_to' => $user->id]);

    Livewire::actingAs($user)
        ->test(LeadShow::class, ['lead' => $lead])
        ->call('openAttribute')
        ->assertForbidden();
});

it('blocks a partner with attribution history from being hard-deleted', function () {
    $partner = Partner::factory()->active()->create();
    app(AttributeLeadToPartner::class)->handle(Lead::factory()->create(), $partner, User::factory()->create());

    expect($partner->fresh()->hasBusinessDependents())->toBeTrue();
});
