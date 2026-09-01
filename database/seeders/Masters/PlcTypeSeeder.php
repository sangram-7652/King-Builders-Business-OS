<?php

declare(strict_types=1);

namespace Database\Seeders\Masters;

use App\Enums\Masters\PlcCalculationType;
use App\Models\Masters\PlcType;
use Illuminate\Database\Seeder;

class PlcTypeSeeder extends Seeder
{
    public function run(): void
    {
        $rows = [
            ['code' => 'CORNER', 'name' => 'Corner plot', 'calculation_type' => PlcCalculationType::Percentage, 'value' => 10.00],
            ['code' => 'PARK', 'name' => 'Park facing', 'calculation_type' => PlcCalculationType::Percentage, 'value' => 7.50],
            ['code' => 'ROAD', 'name' => 'Main road facing', 'calculation_type' => PlcCalculationType::PerSquareFoot, 'value' => 150.00],
            ['code' => 'WIDE', 'name' => 'Wide road (60ft+)', 'calculation_type' => PlcCalculationType::PerSquareFoot, 'value' => 100.00],
            ['code' => 'FIXEDA', 'name' => 'Preferred block', 'calculation_type' => PlcCalculationType::FixedAmount, 'value' => 50000.00],
        ];

        foreach ($rows as $i => $row) {
            PlcType::updateOrCreate(
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
