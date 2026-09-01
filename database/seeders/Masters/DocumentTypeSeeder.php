<?php

declare(strict_types=1);

namespace Database\Seeders\Masters;

use App\Models\Masters\DocumentType;
use Illuminate\Database\Seeder;

class DocumentTypeSeeder extends Seeder
{
    public function run(): void
    {
        $rows = [
            ['code' => 'AADHAAR', 'name' => 'Aadhaar'],
            ['code' => 'PAN', 'name' => 'PAN'],
            ['code' => 'ADDRESS_PROOF', 'name' => 'Address Proof'],
            ['code' => 'PHOTO', 'name' => 'Photograph'],
            ['code' => 'BOOKING_AGREEMENT', 'name' => 'Booking Agreement'],
            ['code' => 'REGISTRY_DOC', 'name' => 'Registry Document'],
            ['code' => 'POSSESSION_LETTER', 'name' => 'Possession Letter'],
            ['code' => 'NOC', 'name' => 'NOC'],
        ];

        foreach ($rows as $i => $row) {
            DocumentType::updateOrCreate(
                ['name' => $row['name']],
                ['code' => $row['code'], 'is_system' => true, 'sort_order' => $i, 'is_active' => true],
            );
        }
    }
}
