<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Booking;
use App\Models\Payment;
use App\Models\Receipt;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Receipt>
 */
class ReceiptFactory extends Factory
{
    protected $model = Receipt::class;

    public function definition(): array
    {
        return [
            'receipt_number' => 'RCPT-'.fake()->unique()->numerify('######'),
            'payment_id' => Payment::factory()->successful(),
            'booking_id' => fn (array $a) => Payment::find($a['payment_id'])?->booking_id ?? Booking::factory()->confirmed(),
            'buyer_id' => null,
            'amount' => 100000,
            'payment_date' => now()->toDateString(),
            'payment_mode_label' => 'Cash',
            'reference_number' => null,
            'buyer_name_snapshot' => fake()->name(),
            'issued_at' => now(),
        ];
    }
}
