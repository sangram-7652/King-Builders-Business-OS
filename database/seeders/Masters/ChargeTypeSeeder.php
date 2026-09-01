<?php

declare(strict_types=1);

namespace Database\Seeders\Masters;

use App\Enums\PriceCalculationType;
use App\Models\Masters\ChargeType;
use Illuminate\Database\Seeder;

class ChargeTypeSeeder extends Seeder
{
    public function run(): void
    {
        $rows = [
            ['code' => 'DEV', 'name' => 'Development charge', 'calculation_type' => PriceCalculationType::PerSqft, 'value' => 200.0000],
            ['code' => 'MAINT', 'name' => 'Maintenance deposit', 'calculation_type' => PriceCalculationType::Fixed, 'value' => 25000.0000],
            ['code' => 'LEGAL', 'name' => 'Legal & documentation', 'calculation_type' => PriceCalculationType::Fixed, 'value' => 15000.0000],
            ['code' => 'CLUB', 'name' => 'Club membership', 'calculation_type' => PriceCalculationType::Fixed, 'value' => 50000.0000],
            ['code' => 'INFRA', 'name' => 'Infrastructure charge', 'calculation_type' => PriceCalculationType::Percentage, 'value' => 3.0000],
        ];

        foreach ($rows as $i => $row) {
            ChargeType::updateOrCreate(
                ['code' => $row['code']],
                [
                    'name' => $row['name'],
                    'calculation_type' => $row['calculation_type'],
                    'value' => $row['value'],
                    'sort_order' => $i,
                    'is_active' => true,
                ],
            );
        }
    }
}
