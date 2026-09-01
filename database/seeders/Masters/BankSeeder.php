<?php

declare(strict_types=1);

namespace Database\Seeders\Masters;

use App\Models\Masters\Bank;
use Illuminate\Database\Seeder;

class BankSeeder extends Seeder
{
    public function run(): void
    {
        $rows = [
            ['code' => 'SBI', 'name' => 'State Bank of India'],
            ['code' => 'HDFC', 'name' => 'HDFC Bank'],
            ['code' => 'ICICI', 'name' => 'ICICI Bank'],
            ['code' => 'AXIS', 'name' => 'Axis Bank'],
            ['code' => 'PNB', 'name' => 'Punjab National Bank'],
            ['code' => 'KOTAK', 'name' => 'Kotak Mahindra Bank'],
            ['code' => 'BOB', 'name' => 'Bank of Baroda'],
        ];

        foreach ($rows as $i => $row) {
            Bank::updateOrCreate(
                ['code' => $row['code']],
                ['name' => $row['name'], 'sort_order' => $i, 'is_active' => true],
            );
        }
    }
}
