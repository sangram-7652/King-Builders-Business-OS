<?php

declare(strict_types=1);

namespace App\Services\Commission;

use App\Models\CommissionRule;
use App\Models\CommissionScheme;
use App\Models\Partner;

/**
 * Resolves which PUBLISHED commission scheme (and rule) applies to a partner
 * (M14.3). Read-only config resolution — it never calculates money. M14.4's
 * calculator calls this, then snapshots the result onto the commission case.
 *
 * Resolution order (first match wins):
 *   1. the partner's explicitly assigned scheme (`commission_scheme_code`)
 *   2. a scheme whose `partner_type` matches the partner's type
 *   3. the scheme flagged `is_default`
 *
 * Only PUBLISHED versions are ever returned. Within the scheme, a
 * project-specific rule overrides the scheme default.
 */
class CommissionSchemeResolver
{
    public function resolveScheme(Partner $partner): ?CommissionScheme
    {
        if ($partner->commission_scheme_code !== null && $partner->commission_scheme_code !== '') {
            $assigned = CommissionScheme::query()->published()
                ->where('code', $partner->commission_scheme_code)
                ->latest('version')
                ->first();

            if ($assigned !== null) {
                return $assigned;
            }
        }

        $byType = CommissionScheme::query()->published()
            ->where('partner_type', $partner->type->value)
            ->latest('version')
            ->first();

        if ($byType !== null) {
            return $byType;
        }

        return CommissionScheme::query()->published()
            ->where('is_default', true)
            ->latest('version')
            ->first();
    }

    /**
     * @return array{scheme: CommissionScheme, rule: CommissionRule}|null
     */
    public function resolveRule(Partner $partner, ?int $projectId): ?array
    {
        $scheme = $this->resolveScheme($partner);

        if ($scheme === null) {
            return null;
        }

        $scheme->loadMissing('rules.slabs');
        $rule = $scheme->ruleForProject($projectId);

        if ($rule === null) {
            return null;
        }

        return ['scheme' => $scheme, 'rule' => $rule];
    }
}
