<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\CommissionCaseStatus;
use App\Models\Booking;
use App\Models\CommissionCase;
use App\Models\Partner;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CommissionCase>
 */
class CommissionCaseFactory extends Factory
{
    protected $model = CommissionCase::class;

    public function definition(): array
    {
        static $seq = 9000;

        return [
            'case_number' => CommissionCase::formatCode($seq++),
            'booking_id' => Booking::factory(),
            'partner_id' => Partner::factory()->active(),
            'status' => CommissionCaseStatus::PendingReview->value,
            'is_eligible' => true,
            'eligibility_reason' => 'Eligible.',
            'eligibility_checked_at' => now(),
            'commission_amount' => 0,
            'paid_amount' => 0,
            'generated_at' => now(),
        ];
    }

    public function status(CommissionCaseStatus $status): static
    {
        return $this->state(fn () => ['status' => $status->value]);
    }
}
