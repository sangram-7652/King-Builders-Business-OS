<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use App\Enums\PriceCalculationType;
use App\Enums\PriceComponentType;
use App\Exceptions\DomainException;
use App\Support\Money;
use App\Support\Pricing\PriceBreakdown;
use App\Support\Pricing\PriceComponentInput;
use App\Support\Pricing\PricingRequest;
use App\Support\Pricing\ResolvedPriceLine;

/**
 * The single, server-side authoritative calculator for a booking's price (M6).
 *
 *   Base (area × rate)
 *   + PLC          (fixed / % of base / per sq ft)
 *   + Other charges (fixed / % of base / per sq ft)
 *   = Subtotal
 *   − Discounts    (fixed / % of subtotal / per sq ft) — may not exceed subtotal
 *   = Taxable amount
 *   + Taxes        (% of taxable / fixed)
 *   = Final amount
 *
 * No rounding happens between steps — only {@see Money::store()} at the very end
 * per line and per total. There is NO calculation logic in Blade or JS; the
 * front end only previews what this returns.
 */
class PricingEngine
{
    public function calculate(PricingRequest $request): PriceBreakdown
    {
        $baseArea = $this->assertNonNegativeNumber($request->baseArea, 'base area');
        $baseRate = $this->assertNonNegativeNumber($request->baseRate, 'base rate');

        $baseAmount = Money::of($baseRate)->multipliedBy($baseArea);

        /** @var list<ResolvedPriceLine> $lines */
        $lines = [new ResolvedPriceLine(
            type: PriceComponentType::Base,
            name: 'Base price',
            calculationType: PriceCalculationType::PerSqft,
            quantity: $baseArea,
            rate: $baseRate,
            amount: $baseAmount,
            sortOrder: PriceComponentType::Base->sortWeight(),
        )];

        $plcTotal = Money::zero();
        $chargeTotal = Money::zero();

        // --- PLC + charges: resolved against the base amount / area ----------
        foreach ($this->componentsOfType($request, PriceComponentType::Plc) as $i => $component) {
            $amount = $this->resolveOnBase($component, $baseAmount, $baseArea);
            $plcTotal = $plcTotal->plus($amount);
            $lines[] = $this->line($component, $amount, $baseArea, PriceComponentType::Plc->sortWeight() + $i);
        }

        foreach ($this->componentsOfType($request, PriceComponentType::Charge) as $i => $component) {
            $amount = $this->resolveOnBase($component, $baseAmount, $baseArea);
            $chargeTotal = $chargeTotal->plus($amount);
            $lines[] = $this->line($component, $amount, $baseArea, PriceComponentType::Charge->sortWeight() + $i);
        }

        $subtotal = $baseAmount->plus($plcTotal)->plus($chargeTotal);

        // --- Discounts: resolved against the subtotal -----------------------
        $discountTotal = Money::zero();
        $discountLines = [];

        foreach ($this->componentsOfType($request, PriceComponentType::Discount) as $i => $component) {
            $amount = match ($component->calculationType) {
                PriceCalculationType::Fixed => Money::of($this->assertNonNegativeNumber($component->rate, 'discount amount')),
                PriceCalculationType::Percentage => $this->assertPercentage($component->rate)->percentageOf($subtotal),
                PriceCalculationType::PerSqft => Money::of($this->assertNonNegativeNumber($component->rate, 'discount rate'))->multipliedBy($baseArea),
            };

            $discountTotal = $discountTotal->plus($amount);
            $discountLines[] = $this->line($component, $amount, $baseArea, PriceComponentType::Discount->sortWeight() + $i);
        }

        if ($discountTotal->greaterThan($subtotal)) {
            throw new DomainException(
                "Discounts (₹{$discountTotal->store()}) cannot exceed the subtotal (₹{$subtotal->store()})."
            );
        }

        $lines = array_merge($lines, $discountLines);
        $taxable = $subtotal->minus($discountTotal);

        // --- Taxes: resolved against the taxable amount --------------------
        $taxTotal = Money::zero();

        foreach ($this->componentsOfType($request, PriceComponentType::Tax) as $i => $component) {
            $amount = match ($component->calculationType) {
                PriceCalculationType::Percentage => $this->assertPercentage($component->rate)->percentageOf($taxable),
                PriceCalculationType::Fixed => Money::of($this->assertNonNegativeNumber($component->rate, 'tax amount')),
                PriceCalculationType::PerSqft => Money::of($this->assertNonNegativeNumber($component->rate, 'tax rate'))->multipliedBy($baseArea),
            };

            $taxTotal = $taxTotal->plus($amount);
            $lines[] = $this->line($component, $amount, $baseArea, PriceComponentType::Tax->sortWeight() + $i);
        }

        // --- Manual override: a flat signed adjustment applied AFTER tax, so
        // an authorised "set the final to X" lands exactly on X. It does not
        // move the subtotal / discount / tax figures.
        $overrideAdjustment = Money::zero();

        foreach ($request->components as $component) {
            if (! ($component->metadata['override'] ?? false)) {
                continue;
            }

            $magnitude = Money::of($this->assertNonNegativeNumber($component->rate, 'override amount'));
            $signed = $component->type->isDeduction() ? Money::zero()->minus($magnitude) : $magnitude;
            $overrideAdjustment = $overrideAdjustment->plus($signed);

            $lines[] = $this->line($component, $magnitude, $baseArea, 90);
        }

        $finalAmount = $taxable->plus($taxTotal)->plus($overrideAdjustment)->clampToZero();

        return new PriceBreakdown(
            baseArea: $baseArea,
            baseRate: $baseRate,
            baseAmount: $baseAmount,
            plcAmount: $plcTotal,
            chargeAmount: $chargeTotal,
            subtotal: $subtotal,
            discountAmount: $discountTotal,
            taxAmount: $taxTotal,
            finalAmount: $finalAmount,
            lines: $lines,
        );
    }

    /**
     * Components of a given type, EXCLUDING manual-override lines (those are
     * applied separately as a post-tax adjustment).
     *
     * @return list<PriceComponentInput>
     */
    private function componentsOfType(PricingRequest $request, PriceComponentType $type): array
    {
        return array_values(array_filter(
            $request->components,
            fn (PriceComponentInput $c) => $c->type === $type && ! ($c->metadata['override'] ?? false),
        ));
    }

    /** Fixed / percentage-of-base / per-sq-ft, used for PLC and charges. */
    private function resolveOnBase(PriceComponentInput $component, Money $baseAmount, string $baseArea): Money
    {
        return match ($component->calculationType) {
            PriceCalculationType::Fixed => Money::of($this->assertNonNegativeNumber($component->rate, "{$component->name} amount")),
            PriceCalculationType::Percentage => $this->assertPercentage($component->rate)->percentageOf($baseAmount),
            PriceCalculationType::PerSqft => Money::of($this->assertNonNegativeNumber($component->rate, "{$component->name} rate"))
                ->multipliedBy($component->quantity ?? $baseArea),
        };
    }

    private function line(PriceComponentInput $component, Money $amount, string $baseArea, int $sortOrder): ResolvedPriceLine
    {
        $quantity = $component->calculationType === PriceCalculationType::PerSqft
            ? ($component->quantity ?? $baseArea)
            : $component->quantity;

        return new ResolvedPriceLine(
            type: $component->type,
            name: $component->name,
            calculationType: $component->calculationType,
            quantity: $quantity,
            rate: $component->rate,
            amount: $amount,
            plcTypeId: $component->plcTypeId,
            chargeTypeId: $component->chargeTypeId,
            taxRateId: $component->taxRateId,
            sortOrder: $sortOrder,
            metadata: $component->metadata,
        );
    }

    private function assertNonNegativeNumber(string $value, string $label): string
    {
        $money = Money::of($value); // rejects non-numeric

        if ($money->isNegative()) {
            throw new DomainException(ucfirst($label).' cannot be negative.');
        }

        return trim($value);
    }

    private function assertPercentage(string $value): Money
    {
        $this->assertNonNegativeNumber($value, 'percentage');
        $money = Money::of($value);

        if ($money->greaterThan(Money::of('100'))) {
            throw new DomainException('A percentage rate cannot exceed 100%.');
        }

        return $money;
    }
}
