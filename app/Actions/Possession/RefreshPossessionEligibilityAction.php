<?php

declare(strict_types=1);

namespace App\Actions\Possession;

use App\Enums\PossessionActivityType;
use App\Enums\PossessionCaseStatus;
use App\Models\PossessionCase;
use App\Models\User;
use App\Services\Possession\PossessionEligibilityService;
use App\Support\Concerns\RunsInTransaction;
use App\Support\Possession\PossessionTimeline;

/**
 * Re-runs the eligibility engine for a possession case and flips it between
 * ELIGIBILITY_PENDING and READY (M10). Only touches eligibility-driven states —
 * a SCHEDULED / INSPECTION / COMPLETED case is never downgraded here.
 */
class RefreshPossessionEligibilityAction
{
    use RunsInTransaction;

    public function __construct(private readonly PossessionEligibilityService $eligibility) {}

    public function handle(PossessionCase $case, ?User $actor = null): PossessionCase
    {
        return $this->transaction(function () use ($case, $actor): PossessionCase {
            /** @var PossessionCase $locked */
            $locked = PossessionCase::query()->whereKey($case->getKey())->lockForUpdate()->firstOrFail();
            $locked->load('booking.plot', 'booking.registryCase');

            $result = $this->eligibility->evaluate($locked->booking);
            $locked->forceFill([
                'eligibility_snapshot' => $result->toArray(),
                'eligibility_checked_at' => now(),
            ])->save();

            if ($locked->status->isEligibilityDriven()) {
                $target = $result->eligible ? PossessionCaseStatus::Ready : PossessionCaseStatus::EligibilityPending;

                if ($target !== $locked->status) {
                    $locked->forceFill([
                        'status' => $target,
                        'eligible_at' => $result->eligible ? now() : null,
                    ])->save();

                    PossessionTimeline::record(
                        PossessionActivityType::PossessionEligibilityChanged,
                        "Possession {$locked->case_number} is now ".($result->eligible ? 'ready.' : 'eligibility pending.'),
                        $locked->booking, $locked->booking->plot, null,
                        ['possession_case_id' => $locked->id, 'eligible' => $result->eligible],
                        $actor,
                    );
                }
            }

            return $locked;
        });
    }
}
