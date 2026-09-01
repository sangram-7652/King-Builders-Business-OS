<?php

declare(strict_types=1);

namespace Database\Seeders\Masters;

use App\Models\Masters\TdsRule;
use Illuminate\Database\Seeder;

class TdsRuleSeeder extends Seeder
{
    public function run(): void
    {
        TdsRule::updateOrCreate(
            ['name' => 'TDS on immovable property (Sec 194-IA)'],
            [
                'percentage' => 1.00,
                'applicable_from' => '2013-06-01',
                'applicable_until' => null,
                'sort_order' => 0,
                'is_active' => true,
            ],
        );
    }
}
