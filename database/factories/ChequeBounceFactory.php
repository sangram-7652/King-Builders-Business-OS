<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ChequeBounce;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChequeBounce>
 */
class ChequeBounceFactory extends Factory
{
    protected $model = ChequeBounce::class;

    public function definition(): array
    {
        return [
            'payment_id' => Payment::factory(),
            'booking_id' => fn (array $a) => Payment::find($a['payment_id'])?->booking_id,
            'bounce_date' => now()->toDateString(),
            'bounce_reason' => 'Insufficient funds',
            'bank_charges' => 500,
            'payment_was_cleared' => false,
        ];
    }
}
