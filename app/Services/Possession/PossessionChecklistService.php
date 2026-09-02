<?php

declare(strict_types=1);

namespace App\Services\Possession;

use App\Enums\InspectionStatus;
use App\Models\PossessionCase;
use App\Support\Possession\PossessionChecklist;

/**
 * Aggregates everything that must be true before a possession case can move to
 * READY_FOR_HANDOVER and be completed (M10):
 *
 *   - every eligibility prerequisite (booking / registry / documents / finance)
 *   - every REQUIRED clearance category is CLEARED / WAIVED
 *   - the latest site inspection is PASSED
 *
 * Configurable via `config/possession.php`. Nothing is stored — no completion
 * percentage, no cached flags.
 */
class PossessionChecklistService
{
    public function __construct(private readonly PossessionEligibilityService $eligibility) {}

    public function for(PossessionCase $case): PossessionChecklist
    {
        $case->loadMissing(['booking.plot', 'booking.registryCase', 'clearances', 'latestInspection']);
        $cfg = config('possession');

        $items = [];

        // --- Eligibility prerequisites --------------------------------
        foreach ($this->eligibility->evaluate($case->booking)->checks as $check) {
            $items[] = [
                'key' => $check['key'],
                'label' => $check['label'],
                'category' => 'eligibility',
                'required' => true,
                'done' => $check['passed'],
                'detail' => $check['detail'],
            ];
        }

        // --- Clearances ---------------------------------------------
        foreach ($case->clearances as $clearance) {
            $required = (bool) ($cfg['clearances'][$clearance->category->value]['required'] ?? $clearance->required);
            $items[] = [
                'key' => 'clearance_'.$clearance->category->value,
                'label' => $clearance->category->label().' clearance',
                'category' => 'clearance',
                'required' => $required,
                'done' => $clearance->status->isSatisfied(),
                'detail' => $clearance->status->isSatisfied() ? null : "{$clearance->category->label()} clearance is {$clearance->status->label()}.",
            ];
        }

        // --- Site inspection --------------------------------------
        if ($cfg['eligibility']['require_inspection_passed']) {
            $inspection = $case->latestInspection;
            $passed = $inspection !== null && $inspection->status === InspectionStatus::Passed;
            $items[] = [
                'key' => 'site_inspection',
                'label' => 'Site inspection passed',
                'category' => 'inspection',
                'required' => true,
                'done' => $passed,
                'detail' => $passed ? null : ($inspection === null ? 'No inspection recorded.' : "Latest inspection is {$inspection->status->label()}."),
            ];
        }

        return new PossessionChecklist($items);
    }
}
