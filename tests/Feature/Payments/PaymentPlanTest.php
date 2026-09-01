<?php

declare(strict_types=1);

use App\Actions\Payments\ActivatePaymentPlanAction;
use App\Actions\Payments\CreatePaymentPlanAction;
use App\Enums\PaymentPlanStatus;
use App\Exceptions\DomainException;
use App\Models\Booking;
use App\Services\Payments\PaymentPlanGenerator;
use App\Support\Money;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function schedule(array $values, string $type = 'percentage'): array
{
    return collect($values)->map(fn ($v, $i) => [
        'type' => $type,
        'value' => (string) $v,
        'due_date' => now()->addMonths($i + 1)->toDateString(),
    ])->all();
}

it('creates a DRAFT plan with installments for a confirmed booking (1)', function () {
    $s = confirmedBookingScenario('1000000');

    $plan = app(CreatePaymentPlanAction::class)->handle($s['booking'], [
        'schedule' => schedule([25, 25, 25, 25]),
    ], $s['actor']);

    expect($plan->status)->toBe(PaymentPlanStatus::Draft)
        ->and($plan->total_amount)->toBe('1000000.00')
        ->and($plan->installments)->toHaveCount(4)
        ->and($plan->installments->pluck('amount')->all())->toBe(['250000.00', '250000.00', '250000.00', '250000.00']);
});

it('activates a plan and seeds installment statuses (2)', function () {
    $s = confirmedBookingScenario();
    $plan = app(CreatePaymentPlanAction::class)->handle($s['booking'], ['schedule' => schedule([50, 50])], $s['actor']);

    $active = app(ActivatePaymentPlanAction::class)->handle($plan, $s['actor']);

    expect($active->status)->toBe(PaymentPlanStatus::Active)
        ->and($active->activated_at)->not->toBeNull()
        ->and($active->approved_by)->toBe($s['actor']->id)
        ->and($active->installments->pluck('status')->map->value->all())
        ->each->toBeIn(['upcoming', 'due', 'overdue']);
});

it('rejects a plan total that does not reconcile with the booking (3)', function () {
    $s = confirmedBookingScenario('1000000');

    expect(fn () => app(CreatePaymentPlanAction::class)->handle($s['booking'], [
        'total_amount' => '900000',
        'schedule' => schedule([50, 50]),
    ], $s['actor']))->toThrow(DomainException::class);

    // …unless variance is explicitly allowed
    $plan = app(CreatePaymentPlanAction::class)->handle($s['booking'], [
        'total_amount' => '900000',
        'allows_variance' => true,
        'schedule' => schedule([50, 50]),
    ], $s['actor']);

    expect($plan->total_amount)->toBe('900000.00');
});

it('generates percentage installments that reconcile exactly (4)', function () {
    $specs = (new PaymentPlanGenerator)->generate(Money::of('1000000'), schedule([10, 20, 20, 20, 30]));

    $sum = collect($specs)->reduce(fn (Money $c, $s) => $c->plus($s->amount), Money::zero());

    expect($sum->equals(Money::of('1000000')))->toBeTrue()
        ->and(collect($specs)->pluck('amount')->map->store()->all())
        ->toBe(['100000.00', '200000.00', '200000.00', '200000.00', '300000.00']);
});

it('generates fixed/custom installments that reconcile exactly (5)', function () {
    $specs = (new PaymentPlanGenerator)->generate(Money::of('1000000'), schedule([300000, 300000, 400000], 'amount'));

    expect(collect($specs)->pluck('amount')->map->store()->all())->toBe(['300000.00', '300000.00', '400000.00']);
});

it('absorbs rounding into the final installment deterministically (6)', function () {
    // 3 × 33.333% of 1,000,000 -> 333333.33 + 333333.33 + 333333.34
    $specs = (new PaymentPlanGenerator)->generate(Money::of('1000000'), schedule([33.333, 33.333, 33.334]));

    $amounts = collect($specs)->pluck('amount')->map->store()->all();

    expect($amounts)->toBe(['333330.00', '333330.00', '333340.00'])
        ->and(collect($specs)->reduce(fn (Money $c, $s) => $c->plus($s->amount), Money::zero())->store())->toBe('1000000.00');
});

it('rejects percentages that do not total exactly 100 (7)', function () {
    expect(fn () => (new PaymentPlanGenerator)->generate(Money::of('1000000'), schedule([30, 30, 30])))
        ->toThrow(DomainException::class, '100%');

    expect(fn () => (new PaymentPlanGenerator)->generate(Money::of('1000000'), schedule([50, 60])))
        ->toThrow(DomainException::class);
});

it('stores explicit due dates on every installment (8)', function () {
    $s = confirmedBookingScenario();
    $dates = [now()->addDays(10)->toDateString(), now()->addDays(40)->toDateString()];

    $plan = app(CreatePaymentPlanAction::class)->handle($s['booking'], [
        'schedule' => [
            ['type' => 'percentage', 'value' => '40', 'due_date' => $dates[0]],
            ['type' => 'percentage', 'value' => '60', 'due_date' => $dates[1]],
        ],
    ], $s['actor']);

    expect($plan->installments->pluck('due_date')->map->toDateString()->all())->toBe($dates);
});

it('allows only one live plan per booking (DB guaranteed)', function () {
    $s = confirmedBookingScenario();
    app(CreatePaymentPlanAction::class)->handle($s['booking'], ['schedule' => schedule([100])], $s['actor']);

    expect(fn () => app(CreatePaymentPlanAction::class)->handle($s['booking'], ['schedule' => schedule([100])], $s['actor']))
        ->toThrow(DomainException::class);

    expect(fn () => Booking::find($s['booking']->id)->paymentPlans()->create([
        'name' => 'x', 'total_amount' => 1000000, 'status' => 'active',
    ]))->toThrow(QueryException::class);
});
