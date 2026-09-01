<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\BuyerStatus;
use App\Enums\Gender;
use App\Models\Buyer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Buyer>
 */
class BuyerFactory extends Factory
{
    protected $model = Buyer::class;

    public function definition(): array
    {
        static $seq = 1000;

        return [
            'customer_code' => Buyer::formatCode($seq++),
            'first_name' => fake()->firstName(),
            'middle_name' => fake()->optional()->firstName(),
            'last_name' => fake()->lastName(),
            'phone' => fake()->numerify('98########'),
            'alternate_phone' => fake()->optional()->numerify('98########'),
            'email' => fake()->optional()->safeEmail(),
            'date_of_birth' => fake()->optional()->dateTimeBetween('-70 years', '-20 years')?->format('Y-m-d'),
            'gender' => fake()->optional()->randomElement(Gender::cases())?->value,
            'occupation' => fake()->optional()->jobTitle(),
            'address' => fake()->optional()->streetAddress(),
            'state_id' => null,
            'city_id' => null,
            'pincode' => fake()->optional()->numerify('######'),
            'pan_number' => null,
            'aadhaar_number' => null,
            'status' => BuyerStatus::Active->value,
        ];
    }

    public function status(BuyerStatus $status): static
    {
        return $this->state(fn () => ['status' => $status->value]);
    }

    public function withSensitiveData(): static
    {
        return $this->state(fn () => [
            'pan_number' => 'ABCDE'.fake()->numerify('####').'F',
            'aadhaar_number' => fake()->numerify('############'),
        ]);
    }
}
