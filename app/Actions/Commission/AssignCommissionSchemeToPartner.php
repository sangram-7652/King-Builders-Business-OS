<?php

declare(strict_types=1);

namespace App\Actions\Commission;

use App\Enums\PartnerActivityType;
use App\Exceptions\DomainException;
use App\Models\CommissionScheme;
use App\Models\Partner;
use App\Models\User;
use App\Support\Concerns\RunsInTransaction;
use Illuminate\Support\Facades\Log;

/**
 * Assigns (or clears) a partner's default commission scheme (M14.3). Stored as
 * the family `code` so it always resolves to the currently-published version;
 * the exact version used is snapshotted onto the commission case in M14.4.
 */
class AssignCommissionSchemeToPartner
{
    use RunsInTransaction;

    public function handle(Partner $partner, ?string $schemeCode, User $actor): Partner
    {
        if ($schemeCode !== null && $schemeCode !== '') {
            $hasPublished = CommissionScheme::query()->published()->where('code', $schemeCode)->exists();
            if (! $hasPublished) {
                throw new DomainException('That commission scheme has no published version to assign.');
            }
        } else {
            $schemeCode = null;
        }

        return $this->transaction(function () use ($partner, $schemeCode, $actor): Partner {
            /** @var Partner $locked */
            $locked = Partner::query()->whereKey($partner->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->commission_scheme_code === $schemeCode) {
                return $locked;
            }

            $locked->forceFill(['commission_scheme_code' => $schemeCode])->save();

            $locked->recordActivity(
                PartnerActivityType::Updated,
                $schemeCode !== null ? "Commission scheme set to {$schemeCode}." : 'Commission scheme assignment cleared.',
                ['commission_scheme_code' => $schemeCode],
                $actor,
            );

            Log::info('partner.commission_scheme_assigned', [
                'partner_id' => $locked->id, 'scheme_code' => $schemeCode, 'by' => $actor->id,
            ]);

            return $locked;
        });
    }
}
