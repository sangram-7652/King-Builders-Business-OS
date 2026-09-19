<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PartnerStatus;
use App\Enums\PartnerType;
use App\Models\Partner;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Partner>
 */
class PartnerFactory extends Factory
{
    protected $model = Partner::class;

    public function definition(): array
    {
        static $seq = 5000;

        $type = fake()->randomElement(PartnerType::cases());

        return [
            'partner_code' => Partner::formatCode($seq++),
            'type' => $type->value,
            'status' => PartnerStatus::Draft->value,
            'name' => fake()->name(),
            'company_name' => $type->isOrganisation() ? fake()->company() : null,
            'contact_person' => $type->isOrganisation() ? fake()->name() : null,
            'phone' => fake()->numerify('97########'),
            'alternate_phone' => fake()->optional()->numerify('97########'),
            'email' => fake()->optional()->safeEmail(),
            'address' => fake()->optional()->streetAddress(),
            'state_id' => null,
            'city_id' => null,
            'pincode' => fake()->optional()->numerify('######'),
            'pan_number' => null,
            'rera_number' => fake()->optional()->bothify('RERA/??/####/######'),
            'commission_percentage' => null,
            'bank_account_name' => null,
            'bank_account_number' => null,
            'bank_ifsc' => null,
            'bank_name' => null,
            'notes' => null,
            'created_by' => null,
        ];
    }

    public function type(PartnerType $type): static
    {
        return $this->state(fn () => [
            'type' => $type->value,
            'company_name' => $type->isOrganisation() ? fake()->company() : null,
        ]);
    }

    public function status(PartnerStatus $status): static
    {
        return $this->state(fn () => ['status' => $status->value]);
    }

    public function active(): static
    {
        return $this->state(fn () => [
            'status' => PartnerStatus::Active->value,
            'onboarded_at' => now()->subMonths(2),
            'approved_at' => now()->subMonths(2),
        ]);
    }

    public function withBankDetails(): static
    {
        return $this->state(fn () => [
            'bank_account_name' => fake()->name(),
            'bank_account_number' => fake()->numerify('##############'),
            'bank_ifsc' => strtoupper(fake()->bothify('????0######')),
            'bank_name' => fake()->company().' Bank',
        ]);
    }

    public function withPan(): static
    {
        return $this->state(fn () => ['pan_number' => 'ABCDE'.fake()->numerify('####').'F']);
    }

    public function commission(string $percentage): static
    {
        return $this->state(fn () => ['commission_percentage' => $percentage]);
    }
}
