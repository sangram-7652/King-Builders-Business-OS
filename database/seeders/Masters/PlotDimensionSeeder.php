<?php

declare(strict_types=1);

namespace Database\Seeders\Masters;

use App\Enums\Masters\LengthUnit;
use App\Models\Masters\PlotDimension;
use Illuminate\Database\Seeder;

class PlotDimensionSeeder extends Seeder
{
    public function run(): void
    {
        $rows = [
            ['width' => 15, 'length' => 30],
            ['width' => 20, 'length' => 45],
            ['width' => 25, 'length' => 50],
            ['width' => 30, 'length' => 40],
            ['width' => 30, 'length' => 60],
            ['width' => 40, 'length' => 60],
        ];

        foreach ($rows as $i => $row) {
            PlotDimension::updateOrCreate(
                ['width' => $row['width'], 'length' => $row['length'], 'unit' => LengthUnit::Feet->value],
                [
                    'display_name' => "{$row['width']} × {$row['length']} ft",
                    'sort_order' => $i,
                    'is_active' => true,
                ],
            );
        }
    }
}
