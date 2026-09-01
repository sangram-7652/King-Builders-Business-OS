<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Lead;
use App\Models\LeadFollowUp;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LeadFollowUp>
 */
class LeadFollowUpFactory extends Factory
{
    protected $model = LeadFollowUp::class;

    public function definition(): array
    {
        return [
            'lead_id' => Lead::factory(),
            'due_at' => fake()->dateTimeBetween('now', '+2 weeks')->format('Y-m-d H:i:s'),
            'note' => fake()->optional()->sentence(),
            'outcome' => null,
            'completed_at' => null,
        ];
    }

    public function completed(): static
    {
        return $this->state(fn () => [
            'completed_at' => now(),
            'outcome' => 'connected',
        ]);
    }
}
