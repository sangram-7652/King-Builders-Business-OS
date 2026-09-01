<?php

declare(strict_types=1);

namespace Database\Seeders\Masters;

use App\Enums\Masters\AreaUnit;
use App\Models\Masters\PlotSize;
use Illuminate\Database\Seeder;

class PlotSizeSeeder extends Seeder
{
    public function run(): void
    {
        $rows = [
            ['name' => '50 sq yd', 'area' => 450.00, 'unit' => AreaUnit::SquareFeet->value],
            ['name' => '100 sq yd', 'area' => 900.00, 'unit' => AreaUnit::SquareFeet->value],
            ['name' => '150 sq yd', 'area' => 1350.00, 'unit' => AreaUnit::SquareFeet->value],
            ['name' => '200 sq yd', 'area' => 1800.00, 'unit' => AreaUnit::SquareFeet->value],
            ['name' => '250 sq yd', 'area' => 2250.00, 'unit' => AreaUnit::SquareFeet->value],
            ['name' => '300 sq yd', 'area' => 2700.00, 'unit' => AreaUnit::SquareFeet->value],
        ];

        foreach ($rows as $i => $row) {
            PlotSize::updateOrCreate(
                ['name' => $row['name']],
                ['area' => $row['area'], 'unit' => $row['unit'], 'sort_order' => $i, 'is_active' => true],
            );
        }
    }
}
