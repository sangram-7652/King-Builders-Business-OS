<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\LeadStatus;
use App\Models\Lead;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Lead>
 */
class LeadFactory extends Factory
{
    protected $model = Lead::class;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'phone' => fake()->numerify('98########'),
            'email' => fake()->optional()->safeEmail(),
            'lead_source_id' => null,
            'assigned_to' => null,
            'status' => LeadStatus::New->value,
            'notes' => fake()->optional()->sentence(),
            'follow_up_at' => null,
            'converted_at' => null,
            'converted_by' => null,
            'buyer_id' => null,
            'created_by' => null,
        ];
    }

    public function status(LeadStatus $status): static
    {
        return $this->state(fn () => ['status' => $status->value]);
    }

    public function qualified(): static
    {
        return $this->status(LeadStatus::Qualified);
    }

    public function assignedTo(int $userId): static
    {
        return $this->state(fn () => ['assigned_to' => $userId]);
    }
}
