<?php

declare(strict_types=1);

namespace Database\Seeders\Masters;

use App\Enums\Masters\InterestFrequency;
use App\Enums\Masters\InterestType;
use App\Models\Masters\InterestRule;
use Illuminate\Database\Seeder;

class InterestRuleSeeder extends Seeder
{
    public function run(): void
    {
        $rows = [
            [
                'name' => 'Standard delayed-payment interest',
                'interest_type' => InterestType::Simple,
                'rate' => 12.0000,
                'frequency' => InterestFrequency::Monthly,
                'grace_period_days' => 7,
            ],
            [
                'name' => 'Concessional interest',
                'interest_type' => InterestType::Simple,
                'rate' => 9.0000,
                'frequency' => InterestFrequency::Monthly,
                'grace_period_days' => 15,
            ],
        ];

        foreach ($rows as $i => $row) {
            InterestRule::updateOrCreate(
                ['name' => $row['name']],
                [
                    'interest_type' => $row['interest_type'],
                    'rate' => $row['rate'],
                    'frequency' => $row['frequency'],
                    'grace_period_days' => $row['grace_period_days'],
                    'effective_from' => '2020-01-01',
                    'effective_until' => null,
                    'sort_order' => $i,
                    'is_active' => true,
                ],
            );
        }
    }
}
