<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PenaltyStatus;
use App\Models\BouncePenalty;
use App\Models\ChequeBounce;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BouncePenalty>
 */
class BouncePenaltyFactory extends Factory
{
    protected $model = BouncePenalty::class;

    public function definition(): array
    {
        return [
            'cheque_bounce_id' => ChequeBounce::factory(),
            'booking_id' => fn (array $a) => ChequeBounce::find($a['cheque_bounce_id'])?->booking_id,
            'penalty_amount' => 2500,
            'reason' => 'Cheque bounce charge',
            'status' => PenaltyStatus::Assessed->value,
            'assessed_at' => now(),
        ];
    }

    public function approved(): static
    {
        return $this->state(fn () => ['status' => PenaltyStatus::Approved->value, 'approved_at' => now()]);
    }
}
