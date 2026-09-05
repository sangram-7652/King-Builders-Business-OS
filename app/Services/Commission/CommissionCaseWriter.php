<?php

declare(strict_types=1);

namespace App\Services\Commission;

use App\Models\BookingPartnerAttribution;
use App\Models\CommissionCalculation;
use App\Models\CommissionCase;
use App\Models\CommissionRule;
use App\Models\CommissionScheme;
use App\Models\User;
use App\Support\Money;

/**
 * Appends one immutable {@see CommissionCalculation} row to a case and updates
 * the case's denormalised pointers / amounts (M14.4). Never mutates an existing
 * calculation row — recalculating writes a new sequence.
 *
 * Must be called inside the caller's transaction.
 */
class CommissionCaseWriter
{
    public function __construct(
        private readonly CommissionBasisResolver $basisResolver,
        private readonly CommissionCalculator $calculator,
    ) {}

    public function write(
        CommissionCase $case,
        CommissionScheme $scheme,
        CommissionRule $rule,
        BookingPartnerAttribution $attribution,
        User $actor,
    ): CommissionCalculation {
        $case->loadMissing('booking');
        $rule->loadMissing('slabs');

        $basisAmount = $this->basisResolver->amountFor($case->booking, $scheme->basis);
        $computation = $this->calculator->calculate($rule, $basisAmount);

        $sharePercent = (string) $attribution->share_percentage;
        $shareAmount = $computation->gross->multipliedBy(bcdiv($sharePercent, '100', 8));

        $sequence = ((int) $case->calculations()->max('sequence')) + 1;

        $snapshot = [
            'generated_at' => now()->toIso8601String(),
            'scheme' => [
                'code' => $scheme->code,
                'version' => $scheme->version,
                'name' => $scheme->name,
                'basis' => $scheme->basis->value,
            ],
            'rule' => [
                'id' => $rule->id,
                'project_id' => $rule->project_id,
                'calc_type' => $rule->calc_type->value,
                'slab_mode' => $rule->slab_mode?->value,
                'rate' => $rule->rate !== null ? (string) $rule->rate : null,
                'flat_amount' => $rule->flat_amount !== null ? (string) $rule->flat_amount : null,
                'min_amount' => $rule->min_amount !== null ? (string) $rule->min_amount : null,
                'max_amount' => $rule->max_amount !== null ? (string) $rule->max_amount : null,
                'slabs' => $rule->slabs->map(fn ($s) => [
                    'from_amount' => (string) $s->from_amount,
                    'to_amount' => $s->to_amount !== null ? (string) $s->to_amount : null,
                    'calc_type' => $s->calc_type->value,
                    'rate' => $s->rate !== null ? (string) $s->rate : null,
                    'flat_amount' => $s->flat_amount !== null ? (string) $s->flat_amount : null,
                ])->all(),
            ],
            'basis' => [
                'type' => $scheme->basis->value,
                'amount' => $basisAmount->store(),
                'source' => $this->basisResolver->sourceLabel($scheme->basis),
            ],
            'computation' => $computation->toArray(),
            'share' => [
                'attribution_id' => $attribution->id,
                'percentage' => $sharePercent,
                'role' => $attribution->role->value,
                'gross' => $computation->gross->store(),
                'amount' => $shareAmount->store(),
            ],
            'commission_amount' => $shareAmount->store(),
        ];

        /** @var CommissionCalculation $calc */
        $calc = $case->calculations()->create([
            'sequence' => $sequence,
            'scheme_code' => $scheme->code,
            'scheme_version' => $scheme->version,
            'basis' => $scheme->basis,
            'basis_amount' => $basisAmount->store(),
            'share_percentage' => $sharePercent,
            'calc_type' => $rule->calc_type,
            'gross_before_caps' => $computation->grossBeforeCaps->store(),
            'gross_amount' => $computation->gross->store(),
            'commission_amount' => $shareAmount->store(),
            'snapshot' => $snapshot,
            'calculated_at' => now(),
            'calculated_by' => $actor->id,
        ]);

        $case->forceFill([
            'booking_partner_attribution_id' => $attribution->id,
            'commission_scheme_id' => $scheme->id,
            'commission_rule_id' => $rule->id,
            'current_calculation_id' => $calc->id,
            'commission_amount' => $shareAmount->store(),
        ])->save();

        return $calc;
    }

    /** Convenience: the effective commission amount for a share of a gross figure. */
    public function shareOf(Money $gross, string $sharePercent): Money
    {
        return $gross->multipliedBy(bcdiv($sharePercent, '100', 8));
    }
}
