<?php

declare(strict_types=1);

use App\Enums\PriceCalculationType as C;
use App\Enums\PriceComponentType as T;
use App\Exceptions\DomainException;
use App\Services\Pricing\PricingEngine;
use App\Support\Money;
use App\Support\Pricing\PriceComponentInput;
use App\Support\Pricing\PricingRequest;

function engine(): PricingEngine
{
    return new PricingEngine;
}

/** @param  list<PriceComponentInput>  $components */
function price(string $area, string $rate, array $components = [])
{
    return engine()->calculate(new PricingRequest($area, $rate, $components));
}

it('computes the base as area × rate with decimal precision (19)', function () {
    $b = price('1776.05', '2000');

    expect($b->baseAmount->store())->toBe('3552100.00')
        ->and($b->finalAmount->store())->toBe('3552100.00')
        ->and($b->lines)->toHaveCount(1);
});

it('resolves a fixed PLC (20)', function () {
    $b = price('1000', '2000', [new PriceComponentInput(T::Plc, 'Preferred block', C::Fixed, '50000')]);

    expect($b->plcAmount->store())->toBe('50000.00')
        ->and($b->subtotal->store())->toBe('2050000.00');
});

it('resolves a percentage PLC against the base amount (21)', function () {
    $b = price('1000', '2000', [new PriceComponentInput(T::Plc, 'Corner', C::Percentage, '5')]);

    // 5% of 2,000,000
    expect($b->plcAmount->store())->toBe('100000.00');
});

it('resolves a per-sq-ft PLC against the plot area (22)', function () {
    $b = price('1776.05', '2000', [new PriceComponentInput(T::Plc, 'Road facing', C::PerSqft, '100')]);

    expect($b->plcAmount->store())->toBe('177605.00');
});

it('resolves other charges by every calculation type (23)', function () {
    $b = price('1000', '2000', [
        new PriceComponentInput(T::Charge, 'Legal', C::Fixed, '15000'),
        new PriceComponentInput(T::Charge, 'Infra', C::Percentage, '3'),   // 3% of 2,000,000 = 60,000
        new PriceComponentInput(T::Charge, 'Development', C::PerSqft, '200'), // 200,000
    ]);

    expect($b->chargeAmount->store())->toBe('275000.00');
});

it('resolves a fixed discount (24)', function () {
    $b = price('1000', '2000', [new PriceComponentInput(T::Discount, 'Loyalty', C::Fixed, '25000')]);

    expect($b->discountAmount->store())->toBe('25000.00')
        ->and($b->finalAmount->store())->toBe('1975000.00');
});

it('resolves a percentage discount against the subtotal (25)', function () {
    $b = price('1000', '2000', [
        new PriceComponentInput(T::Charge, 'Infra', C::Fixed, '100000'), // subtotal = 2,100,000
        new PriceComponentInput(T::Discount, 'Festive', C::Percentage, '2'),
    ]);

    // 2% of 2,100,000
    expect($b->discountAmount->store())->toBe('42000.00');
});

it('rejects a discount that would exceed the subtotal (26)', function () {
    expect(fn () => price('100', '10', [new PriceComponentInput(T::Discount, 'Silly', C::Fixed, '5000')]))
        ->toThrow(DomainException::class);
});

it('computes tax on the taxable amount (subtotal − discount) (27)', function () {
    $b = price('1000', '2000', [
        new PriceComponentInput(T::Discount, 'Loyalty', C::Fixed, '200000'), // taxable = 1,800,000
        new PriceComponentInput(T::Tax, 'GST', C::Percentage, '5'),
    ]);

    expect($b->taxAmount->store())->toBe('90000.00')
        ->and($b->finalAmount->store())->toBe('1890000.00');
});

it('final = base + plc + charges − discount + tax (28)', function () {
    $b = price('1776.05', '2000', [
        new PriceComponentInput(T::Plc, 'Corner', C::Percentage, '5'),
        new PriceComponentInput(T::Charge, 'Development', C::PerSqft, '200'),
        new PriceComponentInput(T::Discount, 'Festive', C::Percentage, '2'),
        new PriceComponentInput(T::Tax, 'GST', C::Percentage, '5'),
    ]);

    $manual = Money::of($b->baseAmount)
        ->plus($b->plcAmount)->plus($b->chargeAmount)
        ->minus($b->discountAmount)->plus($b->taxAmount);

    expect($b->finalAmount->equals($manual))->toBeTrue()
        ->and($b->subtotal->store())->toBe(
            Money::of($b->baseAmount)->plus($b->plcAmount)->plus($b->chargeAmount)->store()
        );
});

it('keeps money exact — no binary float drift (29)', function () {
    // 0.1 + 0.2 style: three lines of 33.333333% of 100.03 must round predictably
    $b = price('1', '100.03', [
        new PriceComponentInput(T::Plc, 'A', C::Percentage, '33.333333'),
        new PriceComponentInput(T::Plc, 'B', C::Percentage, '33.333333'),
        new PriceComponentInput(T::Plc, 'C', C::Percentage, '33.333334'),
    ]);

    // 100.03 * (33.333333 + 33.333333 + 33.333334) / 100 = 100.03 * 100 / 100 = 100.03 (to 2dp)
    expect($b->plcAmount->store())->toBe('100.03')
        ->and($b->finalAmount->store())->toBe('200.06');
});

it('rejects a negative rate (30)', function () {
    expect(fn () => price('1000', '2000', [new PriceComponentInput(T::Charge, 'Weird', C::Fixed, '-500')]))
        ->toThrow(DomainException::class);

    expect(fn () => price('1000', '-1', []))->toThrow(DomainException::class);
});

it('rejects a percentage rate above 100 (31)', function () {
    expect(fn () => price('1000', '2000', [new PriceComponentInput(T::Plc, 'Nope', C::Percentage, '150')]))
        ->toThrow(DomainException::class);
});

it('Money rounds half up at storage precision', function () {
    expect(Money::of('1.005')->store())->toBe('1.01')
        ->and(Money::of('1.004')->store())->toBe('1.00')
        ->and(Money::of('-1.005')->store())->toBe('-1.01')
        ->and(Money::of('2.675')->store())->toBe('2.68'); // the classic float-fails case
});
