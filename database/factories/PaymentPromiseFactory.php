<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PromiseStatus;
use App\Models\CollectionCase;
use App\Models\PaymentPromise;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PaymentPromise>
 */
class PaymentPromiseFactory extends Factory
{
    protected $model = PaymentPromise::class;

    public function definition(): array
    {
        return [
            'collection_case_id' => CollectionCase::factory(),
            'booking_id' => fn (array $a) => CollectionCase::find($a['collection_case_id'])?->booking_id,
            'promised_amount' => 100000,
            'outstanding_at_creation' => 500000,
            'promise_date' => now()->addWeek()->toDateString(),
            'status' => PromiseStatus::Open->value,
        ];
    }

    public function broken(): static
    {
        return $this->state(fn () => [
            'status' => PromiseStatus::Broken->value,
            'promise_date' => now()->subWeek()->toDateString(),
            'broken_at' => now(),
        ]);
    }
}
