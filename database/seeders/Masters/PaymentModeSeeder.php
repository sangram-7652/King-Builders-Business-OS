<?php

declare(strict_types=1);

namespace Database\Seeders\Masters;

use App\Models\Masters\PaymentMode;
use Illuminate\Database\Seeder;

class PaymentModeSeeder extends Seeder
{
    public function run(): void
    {
        $rows = [
            ['code' => 'CASH', 'name' => 'Cash', 'requires_reference' => false, 'is_cheque' => false],
            ['code' => 'CHEQUE', 'name' => 'Cheque', 'requires_reference' => true, 'is_cheque' => true],
            ['code' => 'RTGS', 'name' => 'RTGS', 'requires_reference' => true, 'is_cheque' => false],
            ['code' => 'NEFT', 'name' => 'NEFT', 'requires_reference' => true, 'is_cheque' => false],
            ['code' => 'UPI', 'name' => 'UPI', 'requires_reference' => true, 'is_cheque' => false],
            ['code' => 'BANK_TRANSFER', 'name' => 'Bank Transfer', 'requires_reference' => true, 'is_cheque' => false],
            ['code' => 'CARD', 'name' => 'Card', 'requires_reference' => true, 'is_cheque' => false],
            ['code' => 'ONLINE', 'name' => 'Online', 'requires_reference' => true, 'is_cheque' => false],
            ['code' => 'OTHER', 'name' => 'Other', 'requires_reference' => false, 'is_cheque' => false],
        ];

        foreach ($rows as $i => $row) {
            PaymentMode::updateOrCreate(
                ['code' => $row['code']],
                [
                    'name' => $row['name'],
                    'requires_reference' => $row['requires_reference'],
                    'is_cheque' => $row['is_cheque'],
                    'is_system' => true,
                    'sort_order' => $i,
                    'is_active' => true,
                ],
            );
        }
    }
}
