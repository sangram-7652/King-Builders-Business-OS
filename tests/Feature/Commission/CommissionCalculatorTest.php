<?php

declare(strict_types=1);

use App\Enums\CommissionCalcType;
use App\Enums\SlabMode;
use App\Models\CommissionRule;
use App\Models\CommissionScheme;
use App\Services\Commission\CommissionCalculator;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function ruleWith(array $attrs, array $slabs = []): CommissionRule
{
    $scheme = CommissionScheme::factory()->create();
    /** @var CommissionRule $rule */
    $rule = $scheme->rules()->create(array_merge(['project_id' => null], $attrs));

    foreach ($slabs as $i => $s) {
        $rule->slabs()->create(array_merge(['sort_order' => $i], $s));
    }

    return $rule->load('slabs');
}

it('computes a flat percentage of the basis', function () {
    $rule = ruleWith(['calc_type' => CommissionCalcType::Percentage, 'rate' => '2.5']);

    $c = app(CommissionCalculator::class)->calculate($rule, Money::of('5000000'));

    expect($c->gross->store())->toBe('125000.00');
});

it('computes a fixed amount regardless of basis', function () {
    $rule = ruleWith(['calc_type' => CommissionCalcType::Fixed, 'flat_amount' => '30000']);

    expect(app(CommissionCalculator::class)->calculate($rule, Money::of('5000000'))->gross->store())->toBe('30000.00')
        ->and(app(CommissionCalculator::class)->calculate($rule, Money::of('99'))->gross->store())->toBe('30000.00');
});

it('applies a whole-amount slab — one bracket wins for the entire value', function () {
    $rule = ruleWith(
        ['calc_type' => CommissionCalcType::Slab, 'slab_mode' => SlabMode::Whole],
        [
            ['from_amount' => '0', 'to_amount' => '5000000', 'calc_type' => CommissionCalcType::Percentage, 'rate' => '2'],
            ['from_amount' => '5000000', 'to_amount' => null, 'calc_type' => CommissionCalcType::Percentage, 'rate' => '3'],
        ],
    );

    // 40L falls in bracket 1 → 2% of 40L
    expect(app(CommissionCalculator::class)->calculate($rule, Money::of('4000000'))->gross->store())->toBe('80000.00');
    // 60L falls in bracket 2 → 3% of the WHOLE 60L
    expect(app(CommissionCalculator::class)->calculate($rule, Money::of('6000000'))->gross->store())->toBe('180000.00');
});

it('applies a marginal slab — each tranche taxed at its own rate', function () {
    $rule = ruleWith(
        ['calc_type' => CommissionCalcType::Slab, 'slab_mode' => SlabMode::Marginal],
        [
            ['from_amount' => '0', 'to_amount' => '5000000', 'calc_type' => CommissionCalcType::Percentage, 'rate' => '2'],
            ['from_amount' => '5000000', 'to_amount' => null, 'calc_type' => CommissionCalcType::Percentage, 'rate' => '3'],
        ],
    );

    // 60L → 2% of first 50L (100000) + 3% of next 10L (30000) = 130000
    expect(app(CommissionCalculator::class)->calculate($rule, Money::of('6000000'))->gross->store())->toBe('130000.00');
});

it('applies the rule min / max caps to the gross', function () {
    $capped = ruleWith(['calc_type' => CommissionCalcType::Percentage, 'rate' => '5', 'max_amount' => '200000']);
    $c = app(CommissionCalculator::class)->calculate($capped, Money::of('10000000')); // 5% = 500000 → capped
    expect($c->gross->store())->toBe('200000.00')
        ->and($c->maxApplied?->store())->toBe('200000.00');

    $floored = ruleWith(['calc_type' => CommissionCalcType::Percentage, 'rate' => '1', 'min_amount' => '50000']);
    $c = app(CommissionCalculator::class)->calculate($floored, Money::of('1000000')); // 1% = 10000 → floored
    expect($c->gross->store())->toBe('50000.00')
        ->and($c->minApplied?->store())->toBe('50000.00');
});

it('returns zero when the basis is below the lowest whole slab bracket', function () {
    $rule = ruleWith(
        ['calc_type' => CommissionCalcType::Slab, 'slab_mode' => SlabMode::Whole],
        [['from_amount' => '1000000', 'to_amount' => null, 'calc_type' => CommissionCalcType::Percentage, 'rate' => '2']],
    );

    expect(app(CommissionCalculator::class)->calculate($rule, Money::of('500000'))->gross->store())->toBe('0.00');
});
