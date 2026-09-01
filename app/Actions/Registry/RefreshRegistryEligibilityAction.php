<?php

declare(strict_types=1);

namespace App\Actions\Registry;

use App\Enums\DocumentActivityType;
use App\Enums\RegistryCaseStatus;
use App\Models\RegistryCase;
use App\Models\User;
use App\Services\Registry\RegistryEligibilityService;
use App\Support\Concerns\RunsInTransaction;
use App\Support\Documents\DocumentTimeline;

/**
 * Re-runs the eligibility engine for a registry case and flips it between
 * ELIGIBILITY_PENDING and READY (M9). Only touches eligibility-driven states —
 * a SCHEDULED / IN_PROCESS / COMPLETED case is never downgraded here.
 */
class RefreshRegistryEligibilityAction
{
    use RunsInTransaction;

    public function __construct(private readonly RegistryEligibilityService $eligibility) {}

    public function handle(RegistryCase $case, ?User $actor = null): RegistryCase
    {
        return $this->transaction(function () use ($case, $actor): RegistryCase {
            /** @var RegistryCase $locked */
            $locked = RegistryCase::query()->whereKey($case->getKey())->lockForUpdate()->firstOrFail();
            $locked->load('booking.plot', 'booking.primaryBookingBuyer.buyer', 'booking.agreement');

            $result = $this->eligibility->evaluate($locked->booking);
            $locked->forceFill([
                'eligibility_snapshot' => $result->toArray(),
                'eligibility_checked_at' => now(),
            ])->save();

            if ($locked->status->isEligibilityDriven()) {
                $target = $result->eligible ? RegistryCaseStatus::Ready : RegistryCaseStatus::EligibilityPending;

                if ($target !== $locked->status) {
                    $locked->forceFill(['status' => $target])->save();

                    DocumentTimeline::record(
                        DocumentActivityType::RegistryEligibilityChanged,
                        "Registry {$locked->case_number} is now ".($result->eligible ? 'ready.' : 'eligibility pending.'),
                        $locked->booking, null,
                        ['registry_case_id' => $locked->id, 'eligible' => $result->eligible],
                        $actor,
                    );
                }
            }

            return $locked;
        });
    }
}
