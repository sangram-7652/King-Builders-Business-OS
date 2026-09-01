<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\CollectionCase;
use App\Models\CollectionFollowUp;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CollectionFollowUp>
 */
class CollectionFollowUpFactory extends Factory
{
    protected $model = CollectionFollowUp::class;

    public function definition(): array
    {
        return [
            'collection_case_id' => CollectionCase::factory(),
            'booking_id' => fn (array $a) => CollectionCase::find($a['collection_case_id'])?->booking_id,
            'follow_up_at' => now()->addDay(),
            'notes' => $this->faker->optional()->sentence(),
        ];
    }

    public function completed(string $outcome = 'contacted'): static
    {
        return $this->state(fn () => ['outcome' => $outcome, 'completed_at' => now()]);
    }
}
