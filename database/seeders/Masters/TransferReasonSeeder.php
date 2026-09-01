<?php

declare(strict_types=1);

namespace Database\Seeders\Masters;

use App\Models\Masters\TransferReason;
use Illuminate\Database\Seeder;

class TransferReasonSeeder extends Seeder
{
    public function run(): void
    {
        $rows = [
            ['name' => 'Family Transfer', 'description' => 'Transfer to a family member'],
            ['name' => 'Sale Transfer', 'description' => 'Transfer following a resale'],
            ['name' => 'Correction', 'description' => 'Data / name correction'],
            ['name' => 'Administrative', 'description' => 'Company-initiated transfer'],
            ['name' => 'Other', 'description' => null],
        ];

        foreach ($rows as $i => $row) {
            TransferReason::updateOrCreate(
                ['name' => $row['name']],
                ['description' => $row['description'], 'is_system' => true, 'sort_order' => $i, 'is_active' => true],
            );
        }
    }
}
