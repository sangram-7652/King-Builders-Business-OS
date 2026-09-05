<?php

declare(strict_types=1);

namespace App\Services\Commission;

use App\Enums\CommissionCalcType;
use App\Enums\SlabMode;
use App\Exceptions\DomainException;
use App\Models\CommissionRule;
use App\Models\CommissionSlab;
use App\Support\Commission\CommissionComputation;
use App\Support\Money;
use Illuminate\Support\Collection;

/**
 * Turns a commission rule + a basis amount into a scheme-level commission
 * figure (M14.4). Pure — no database writes, no partner-share split (the caller
 * applies the co-broker share). All arithmetic is bcmath via {@see Money}.
 *
 * The basis amount MUST come from M6/M7 truth (see {@see CommissionBasisResolver});
 * this class never invents a number.
 */
class CommissionCalculator
{
    public function calculate(CommissionRule $rule, Money $basisAmount): CommissionComputation
    {
        [$grossBeforeCaps, $breakdown] = match ($rule->calc_type) {
            CommissionCalcType::Percentage => $this->percentage($rule, $basisAmount),
            CommissionCalcType::Fixed => $this->fixed($rule),
            CommissionCalcType::Slab => $this->slab($rule, $basisAmount),
        };

        [$gross, $minApplied, $maxApplied] = $this->applyCaps($rule, $grossBeforeCaps);

        return new CommissionComputation(
            calcType: $rule->calc_type,
            basisAmount: $basisAmount,
            grossBeforeCaps: $grossBeforeCaps,
            gross: $gross,
            minApplied: $minApplied,
            maxApplied: $maxApplied,
            breakdown: $breakdown,
        );
    }

    /**
     * @return array{0: Money, 1: list<array{label: string, detail: string, amount: string}>}
     */
    private function percentage(CommissionRule $rule, Money $basis): array
    {
        $rate = (string) ($rule->rate ?? '0');
        $amount = Money::of($rate)->percentageOf($basis);

        return [$amount, [[
            'label' => 'Percentage',
            'detail' => "{$this->trim($rate)}% of ".$basis->store(),
            'amount' => $amount->store(),
        ]]];
    }

    /**
     * @return array{0: Money, 1: list<array{label: string, detail: string, amount: string}>}
     */
    private function fixed(CommissionRule $rule): array
    {
        $amount = Money::of($rule->flat_amount ?? '0');

        return [$amount, [[
            'label' => 'Fixed',
            'detail' => 'Flat amount',
            'amount' => $amount->store(),
        ]]];
    }

    /**
     * @return array{0: Money, 1: list<array{label: string, detail: string, amount: string}>}
     */
    private function slab(CommissionRule $rule, Money $basis): array
    {
        $slabs = $rule->relationLoaded('slabs') ? $rule->slabs : $rule->slabs()->get();

        if ($slabs->isEmpty()) {
            throw new DomainException('The slab rule has no brackets to apply.');
        }

        $mode = $rule->slab_mode ?? SlabMode::Whole;

        return $mode === SlabMode::Marginal
            ? $this->slabMarginal($slabs, $basis)
            : $this->slabWhole($slabs, $basis);
    }

    /**
     * @param  Collection<int, CommissionSlab>  $slabs
     * @return array{0: Money, 1: list<array{label: string, detail: string, amount: string}>}
     */
    private function slabWhole($slabs, Money $basis): array
    {
        foreach ($slabs as $slab) {
            $from = Money::of($slab->from_amount);
            $to = $slab->to_amount === null ? null : Money::of($slab->to_amount);

            $matches = ! $basis->lessThan($from) && ($to === null || $basis->lessThan($to));

            if ($matches) {
                $amount = $this->applySlabRate($slab, $basis);

                return [$amount, [[
                    'label' => 'Slab (whole)',
                    'detail' => $this->bracketLabel($slab).' on '.$basis->store(),
                    'amount' => $amount->store(),
                ]]];
            }
        }

        // Basis below the first bracket → no commission.
        return [Money::zero(), [[
            'label' => 'Slab (whole)',
            'detail' => 'Basis '.$basis->store().' is below the lowest bracket',
            'amount' => '0.00',
        ]]];
    }

    /**
     * @param  Collection<int, CommissionSlab>  $slabs
     * @return array{0: Money, 1: list<array{label: string, detail: string, amount: string}>}
     */
    private function slabMarginal($slabs, Money $basis): array
    {
        $total = Money::zero();
        $breakdown = [];

        foreach ($slabs as $slab) {
            $from = Money::of($slab->from_amount);

            if (! $basis->greaterThan($from)) {
                continue;
            }

            $to = $slab->to_amount === null ? $basis : Money::min($basis, Money::of($slab->to_amount));
            $tranche = $to->minus($from);

            if (! $tranche->isPositive()) {
                continue;
            }

            $amount = $this->applySlabRate($slab, $tranche);
            $total = $total->plus($amount);

            $breakdown[] = [
                'label' => 'Tranche '.$from->store().'–'.($slab->to_amount === null ? '∞' : Money::of($slab->to_amount)->store()),
                'detail' => $this->bracketLabel($slab).' on '.$tranche->store(),
                'amount' => $amount->store(),
            ];
        }

        return [$total, $breakdown];
    }

    private function applySlabRate(CommissionSlab $slab, Money $amount): Money
    {
        return $slab->calc_type === CommissionCalcType::Fixed
            ? Money::of($slab->flat_amount ?? '0')
            : Money::of((string) ($slab->rate ?? '0'))->percentageOf($amount);
    }

    /**
     * @return array{0: Money, 1: ?Money, 2: ?Money}
     */
    private function applyCaps(CommissionRule $rule, Money $gross): array
    {
        $minApplied = null;
        $maxApplied = null;

        if ($rule->min_amount !== null) {
            $min = Money::of($rule->min_amount);
            if ($gross->lessThan($min)) {
                $gross = $min;
                $minApplied = $min;
            }
        }

        if ($rule->max_amount !== null) {
            $max = Money::of($rule->max_amount);
            if ($gross->greaterThan($max)) {
                $gross = $max;
                $maxApplied = $max;
            }
        }

        return [$gross, $minApplied, $maxApplied];
    }

    private function bracketLabel(CommissionSlab $slab): string
    {
        return $slab->calc_type === CommissionCalcType::Fixed
            ? '₹'.Money::of($slab->flat_amount ?? '0')->store()
            : $this->trim((string) ($slab->rate ?? '0')).'%';
    }

    private function trim(string $number): string
    {
        return str_contains($number, '.') ? rtrim(rtrim($number, '0'), '.') : $number;
    }
}
