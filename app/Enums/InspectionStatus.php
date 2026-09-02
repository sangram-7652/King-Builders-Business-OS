<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

/**
 * Site inspection outcome (M10). A FAILED or REINSPECTION_REQUIRED inspection
 * blocks the final possession handover until a later PASSED inspection is
 * recorded.
 */
enum InspectionStatus: string
{
    use HasLabel;

    case Pending = 'pending';
    case Passed = 'passed';
    case Failed = 'failed';
    case ReinspectionRequired = 'reinspection_required';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Passed => 'Passed',
            self::Failed => 'Failed',
            self::ReinspectionRequired => 'Re-inspection required',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'muted',
            self::Passed => 'success',
            self::Failed => 'danger',
            self::ReinspectionRequired => 'warning',
        };
    }

    public function isPassed(): bool
    {
        return $this === self::Passed;
    }

    public function blocksHandover(): bool
    {
        return in_array($this, [self::Failed, self::ReinspectionRequired], true);
    }
}
