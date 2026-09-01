<?php

declare(strict_types=1);

namespace Database\Seeders\Masters;

use App\Models\Masters\TaxRate;
use Illuminate\Database\Seeder;

class TaxRateSeeder extends Seeder
{
    public function run(): void
    {
        $rows = [
            ['code' => 'GST_PLOT', 'name' => 'GST on plot sale', 'percentage' => 5.0000],
            ['code' => 'GST_SERVICES', 'name' => 'GST on services', 'percentage' => 18.0000],
            ['code' => 'STAMP', 'name' => 'Stamp duty (indicative)', 'percentage' => 6.0000],
        ];

        foreach ($rows as $i => $row) {
            TaxRate::updateOrCreate(
                ['code' => $row['code']],
                [
                    'name' => $row['name'],
                    'percentage' => $row['percentage'],
                    'sort_order' => $i,
                    'is_active' => true,
                ],
            );
        }
    }
}
