<?php

declare(strict_types=1);

namespace Database\Seeders\Masters;

use App\Models\Masters\PaymentType;
use Illuminate\Database\Seeder;

class PaymentTypeSeeder extends Seeder
{
    public function run(): void
    {
        $rows = [
            ['code' => 'BOOKING', 'name' => 'Booking Amount'],
            ['code' => 'DOWN_PAYMENT', 'name' => 'Down Payment'],
            ['code' => 'INSTALLMENT', 'name' => 'Installment'],
            ['code' => 'REGISTRY', 'name' => 'Registry Payment'],
            ['code' => 'OTHER_CHARGES', 'name' => 'Other Charges'],
            ['code' => 'REFUND', 'name' => 'Refund'],
            ['code' => 'ADJUSTMENT', 'name' => 'Adjustment'],
        ];

        foreach ($rows as $i => $row) {
            PaymentType::updateOrCreate(
                ['code' => $row['code']],
                ['name' => $row['name'], 'is_system' => true, 'sort_order' => $i, 'is_active' => true],
            );
        }
    }
}
