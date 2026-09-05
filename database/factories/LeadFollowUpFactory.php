<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\FollowUpPriority;
use App\Enums\FollowUpStatus;
use App\Enums\FollowUpType;
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
            'title' => fake()->optional()->sentence(3),
            'type' => fake()->randomElement(FollowUpType::cases()),
            'priority' => FollowUpPriority::Normal,
            'status' => FollowUpStatus::Pending,
            'assigned_to' => null,
            'due_at' => fake()->dateTimeBetween('now', '+2 weeks')->format('Y-m-d H:i:s'),
            'note' => fake()->optional()->sentence(),
            'outcome' => null,
            'completed_at' => null,
        ];
    }

    public function pending(): static
    {
        return $this->state(fn () => ['status' => FollowUpStatus::Pending, 'completed_at' => null]);
    }

    public function overdue(): static
    {
        return $this->state(fn () => [
            'status' => FollowUpStatus::Pending,
            'due_at' => now()->subDays(2),
            'completed_at' => null,
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn () => [
            'status' => FollowUpStatus::Completed,
            'completed_at' => now(),
            'outcome' => 'connected',
        ]);
    }

    public function missed(): static
    {
        return $this->state(fn () => [
            'status' => FollowUpStatus::Missed,
            'due_at' => now()->subDays(3),
            'completed_at' => null,
        ]);
    }
}
