<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\CommunicationCategory;
use App\Enums\CommunicationChannel;
use App\Enums\CommunicationStatus;
use App\Models\Communication;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Communication>
 */
class CommunicationFactory extends Factory
{
    protected $model = Communication::class;

    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'channel' => CommunicationChannel::Email->value,
            'category' => CommunicationCategory::Transactional->value,
            'status' => CommunicationStatus::Pending->value,
            'to_address' => fake()->safeEmail(),
            'subject' => fake()->sentence(4),
            'body' => fake()->paragraph(),
            'context' => null,
            'attempts' => 0,
        ];
    }

    public function channel(CommunicationChannel $channel): static
    {
        return $this->state(fn () => [
            'channel' => $channel->value,
            'to_address' => $channel === CommunicationChannel::Email ? fake()->safeEmail() : fake()->numerify('+9198########'),
            'subject' => $channel === CommunicationChannel::Email ? fake()->sentence(4) : null,
        ]);
    }

    public function status(CommunicationStatus $status): static
    {
        return $this->state(fn () => ['status' => $status->value]);
    }

    public function failed(): static
    {
        return $this->state(fn () => [
            'status' => CommunicationStatus::Failed->value,
            'failed_at' => now(),
            'error' => 'provider rejected the recipient',
            'attempts' => 3,
        ]);
    }
}
