<?php

declare(strict_types=1);

namespace Database\Seeders\Masters;

use App\Models\Masters\CancellationReason;
use Illuminate\Database\Seeder;

class CancellationReasonSeeder extends Seeder
{
    public function run(): void
    {
        $rows = [
            ['name' => 'Customer Request', 'description' => 'Cancellation requested by the customer'],
            ['name' => 'Payment Default', 'description' => 'Cancelled due to non-payment / default'],
            ['name' => 'Administrative', 'description' => 'Cancelled by the company for administrative reasons'],
            ['name' => 'Other', 'description' => null],
        ];

        foreach ($rows as $i => $row) {
            CancellationReason::updateOrCreate(
                ['name' => $row['name']],
                ['description' => $row['description'], 'is_system' => true, 'sort_order' => $i, 'is_active' => true],
            );
        }
    }
}
