<?php

declare(strict_types=1);

use App\Actions\Commission\ArchiveCommissionScheme;
use App\Actions\Commission\AssignCommissionSchemeToPartner;
use App\Actions\Commission\CreateCommissionScheme;
use App\Actions\Commission\CreateCommissionSchemeVersion;
use App\Actions\Commission\PublishCommissionScheme;
use App\Actions\Commission\SaveCommissionRule;
use App\Actions\Commission\UpdateCommissionScheme;
use App\Enums\CommissionCalcType;
use App\Enums\CommissionSchemeStatus;
use App\Enums\PartnerType;
use App\Exceptions\DomainException;
use App\Models\CommissionScheme;
use App\Models\Partner;
use App\Models\Project;
use App\Models\User;
use App\Services\Commission\CommissionSchemeResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(fn () => seedRbac());

/** @return array<string, mixed> */
function schemeData(array $o = []): array
{
    return array_merge([
        'name' => 'Standard broker',
        'description' => null,
        'basis' => 'booking_value',
        'partner_type' => null,
        'is_default' => false,
        'effective_from' => null,
        'effective_to' => null,
    ], $o);
}

it('creates a scheme at version 1 in draft with a sequential code', function () {
    $actor = User::factory()->create();

    $a = app(CreateCommissionScheme::class)->handle(schemeData(), $actor);
    $b = app(CreateCommissionScheme::class)->handle(schemeData(['name' => 'Other']), $actor);

    expect($a->code)->toStartWith('CMS-')
        ->and($a->version)->toBe(1)
        ->and($a->status)->toBe(CommissionSchemeStatus::Draft)
        ->and($b->code)->not->toBe($a->code);
});

it('refuses to publish a scheme with no default rule', function () {
    $scheme = app(CreateCommissionScheme::class)->handle(schemeData(), User::factory()->create());

    expect(fn () => app(PublishCommissionScheme::class)->handle($scheme, User::factory()->create()))
        ->toThrow(DomainException::class, 'default calculation rule');
});

it('freezes a published version — no metadata or rule edits', function () {
    $actor = User::factory()->create();
    $scheme = CommissionScheme::factory()->withPercentageRule()->create();
    app(PublishCommissionScheme::class)->handle($scheme, $actor);

    expect(fn () => app(UpdateCommissionScheme::class)->handle($scheme->fresh(), schemeData(), $actor))
        ->toThrow(DomainException::class);
    expect(fn () => app(SaveCommissionRule::class)->handle($scheme->fresh(), ['calc_type' => 'percentage', 'rate' => '9'], $actor))
        ->toThrow(DomainException::class);
});

it('stamps effective_from and archives the previously published version on publish', function () {
    $actor = User::factory()->create();
    $v1 = CommissionScheme::factory()->withPercentageRule('2')->create();
    app(PublishCommissionScheme::class)->handle($v1, $actor);

    $v2 = app(CreateCommissionSchemeVersion::class)->handle($v1->fresh(), $actor);
    app(SaveCommissionRule::class)->handle($v2, ['calc_type' => 'percentage', 'rate' => '3'], $actor);
    app(PublishCommissionScheme::class)->handle($v2->fresh(), $actor);

    expect($v1->fresh()->status)->toBe(CommissionSchemeStatus::Archived)
        ->and($v2->fresh()->status)->toBe(CommissionSchemeStatus::Published)
        ->and($v2->fresh()->effective_from)->not->toBeNull()
        ->and(CommissionScheme::query()->published()->where('code', $v1->code)->count())->toBe(1);
});

it('is idempotent when publishing an already-published version', function () {
    $actor = User::factory()->create();
    $scheme = CommissionScheme::factory()->withPercentageRule()->create();
    app(PublishCommissionScheme::class)->handle($scheme, $actor);
    app(PublishCommissionScheme::class)->handle($scheme->fresh(), $actor);

    expect(CommissionScheme::where('code', $scheme->code)->count())->toBe(1);
});

it('saves each rule calc type', function () {
    $actor = User::factory()->create();

    $pct = CommissionScheme::factory()->create();
    $rule = app(SaveCommissionRule::class)->handle($pct, ['calc_type' => 'percentage', 'rate' => '2.75', 'max_amount' => '500000'], $actor);
    expect($rule->calc_type)->toBe(CommissionCalcType::Percentage)
        ->and((string) $rule->rate)->toBe('2.7500')
        ->and((string) $rule->max_amount)->toBe('500000.00');

    $fixed = CommissionScheme::factory()->create();
    $rule = app(SaveCommissionRule::class)->handle($fixed, ['calc_type' => 'fixed', 'flat_amount' => '25000'], $actor);
    expect($rule->calc_type)->toBe(CommissionCalcType::Fixed)
        ->and((string) $rule->flat_amount)->toBe('25000.00');

    $slab = CommissionScheme::factory()->create();
    $rule = app(SaveCommissionRule::class)->handle($slab, [
        'calc_type' => 'slab', 'slab_mode' => 'marginal',
        'slabs' => [
            ['from_amount' => '0', 'to_amount' => '5000000', 'calc_type' => 'percentage', 'rate' => '2'],
            ['from_amount' => '5000000', 'to_amount' => null, 'calc_type' => 'percentage', 'rate' => '3'],
        ],
    ], $actor);
    expect($rule->slabs)->toHaveCount(2)
        ->and((string) $rule->slabs->first()->from_amount)->toBe('0.00')
        ->and($rule->slabs->last()->to_amount)->toBeNull();
});

it('rejects a non-contiguous or badly bounded slab set', function () {
    $actor = User::factory()->create();
    $scheme = CommissionScheme::factory()->create();

    // gap between brackets
    expect(fn () => app(SaveCommissionRule::class)->handle($scheme, [
        'calc_type' => 'slab', 'slab_mode' => 'whole',
        'slabs' => [
            ['from_amount' => '0', 'to_amount' => '1000000', 'calc_type' => 'percentage', 'rate' => '2'],
            ['from_amount' => '2000000', 'to_amount' => null, 'calc_type' => 'percentage', 'rate' => '3'],
        ],
    ], $actor))->toThrow(DomainException::class, 'contiguous');

    // last bracket not open-ended
    expect(fn () => app(SaveCommissionRule::class)->handle($scheme, [
        'calc_type' => 'slab', 'slab_mode' => 'whole',
        'slabs' => [
            ['from_amount' => '0', 'to_amount' => '1000000', 'calc_type' => 'percentage', 'rate' => '2'],
        ],
    ], $actor))->toThrow(DomainException::class, 'open-ended');
});

it('clones rules and slabs into a new draft version', function () {
    $actor = User::factory()->create();
    $v1 = CommissionScheme::factory()->create();
    app(SaveCommissionRule::class)->handle($v1, [
        'calc_type' => 'slab', 'slab_mode' => 'whole',
        'slabs' => [
            ['from_amount' => '0', 'to_amount' => '5000000', 'calc_type' => 'percentage', 'rate' => '2'],
            ['from_amount' => '5000000', 'to_amount' => null, 'calc_type' => 'percentage', 'rate' => '3'],
        ],
    ], $actor);
    app(PublishCommissionScheme::class)->handle($v1->fresh(), $actor);

    $v2 = app(CreateCommissionSchemeVersion::class)->handle($v1->fresh(), $actor);

    expect($v2->version)->toBe(2)
        ->and($v2->status)->toBe(CommissionSchemeStatus::Draft)
        ->and($v2->defaultRule->slabs)->toHaveCount(2);

    // only one open draft at a time
    expect(fn () => app(CreateCommissionSchemeVersion::class)->handle($v1->fresh(), $actor))
        ->toThrow(DomainException::class, 'open draft');
});

it('archives a draft or published version', function () {
    $actor = User::factory()->create();
    $draft = CommissionScheme::factory()->create();
    app(ArchiveCommissionScheme::class)->handle($draft, $actor);
    expect($draft->fresh()->status)->toBe(CommissionSchemeStatus::Archived);

    $published = CommissionScheme::factory()->published()->create();
    app(ArchiveCommissionScheme::class)->handle($published, $actor);
    expect($published->fresh()->status)->toBe(CommissionSchemeStatus::Archived);
});

it('resolves the scheme for a partner: assigned > type-match > default', function () {
    $actor = User::factory()->create();
    $resolver = app(CommissionSchemeResolver::class);

    $default = CommissionScheme::factory()->default()->published('1')->create(['name' => 'Fallback']);
    $forFirms = CommissionScheme::factory()->published('2')->create(['name' => 'Firms', 'partner_type' => PartnerType::Firm->value]);
    $special = CommissionScheme::factory()->published('5')->create(['name' => 'Special']);

    $firm = Partner::factory()->active()->type(PartnerType::Firm)->create();
    expect($resolver->resolveScheme($firm)->code)->toBe($forFirms->code);

    $individual = Partner::factory()->active()->type(PartnerType::Individual)->create();
    expect($resolver->resolveScheme($individual)->code)->toBe($default->code);

    app(AssignCommissionSchemeToPartner::class)->handle($firm, $special->code, $actor);
    expect($resolver->resolveScheme($firm->fresh())->code)->toBe($special->code);
});

it('refuses to assign a scheme with no published version', function () {
    $draftOnly = CommissionScheme::factory()->create();

    expect(fn () => app(AssignCommissionSchemeToPartner::class)->handle(
        Partner::factory()->active()->create(), $draftOnly->code, User::factory()->create(),
    ))->toThrow(DomainException::class, 'no published version');
});

it('prefers a project-specific rule over the scheme default', function () {
    $actor = User::factory()->create();
    $project = Project::factory()->create();
    $scheme = CommissionScheme::factory()->create();

    app(SaveCommissionRule::class)->handle($scheme, ['calc_type' => 'percentage', 'rate' => '2'], $actor);
    app(SaveCommissionRule::class)->handle($scheme->fresh(), ['project_id' => $project->id, 'calc_type' => 'percentage', 'rate' => '4'], $actor);
    app(PublishCommissionScheme::class)->handle($scheme->fresh(), $actor);

    $partner = Partner::factory()->active()->create(['commission_scheme_code' => $scheme->code]);
    $resolved = app(CommissionSchemeResolver::class)->resolveRule($partner, $project->id);

    expect((string) $resolved['rule']->rate)->toBe('4.0000')
        ->and((string) app(CommissionSchemeResolver::class)->resolveRule($partner, null)['rule']->rate)->toBe('2.0000');
});
