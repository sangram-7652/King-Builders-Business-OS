<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\CommissionBasis;
use App\Enums\CommissionCalcType;
use App\Enums\CommissionSchemeStatus;
use App\Models\CommissionScheme;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CommissionScheme>
 */
class CommissionSchemeFactory extends Factory
{
    protected $model = CommissionScheme::class;

    public function definition(): array
    {
        static $seq = 7000;

        return [
            'code' => CommissionScheme::formatCode($seq++),
            'version' => 1,
            'name' => fake()->unique()->words(2, true).' commission',
            'description' => fake()->optional()->sentence(),
            'status' => CommissionSchemeStatus::Draft->value,
            'basis' => CommissionBasis::BookingValue->value,
            'partner_type' => null,
            'is_default' => false,
            'effective_from' => null,
            'effective_to' => null,
        ];
    }

    public function status(CommissionSchemeStatus $status): static
    {
        return $this->state(fn () => ['status' => $status->value]);
    }

    public function default(): static
    {
        return $this->state(fn () => ['is_default' => true]);
    }

    /** A scheme with a flat percentage default rule, still a draft. */
    public function withPercentageRule(string $rate = '2.5'): static
    {
        return $this->afterCreating(function (CommissionScheme $scheme) use ($rate): void {
            $scheme->rules()->create([
                'project_id' => null,
                'calc_type' => CommissionCalcType::Percentage,
                'rate' => $rate,
            ]);
        });
    }

    /** A published scheme with a flat percentage default rule. */
    public function published(string $rate = '2.5'): static
    {
        return $this->withPercentageRule($rate)->state(fn () => [
            'status' => CommissionSchemeStatus::Published->value,
            'published_at' => now(),
            'effective_from' => now()->toDateString(),
        ]);
    }
}
