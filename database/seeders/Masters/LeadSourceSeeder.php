<?php

declare(strict_types=1);

namespace Database\Seeders\Masters;

use App\Models\Masters\LeadSource;
use Illuminate\Database\Seeder;

class LeadSourceSeeder extends Seeder
{
    public function run(): void
    {
        $rows = [
            ['code' => 'WEBSITE', 'name' => 'Website'],
            ['code' => 'GOOGLE_ADS', 'name' => 'Google Ads'],
            ['code' => 'META_ADS', 'name' => 'Meta Ads'],
            ['code' => 'WHATSAPP', 'name' => 'WhatsApp'],
            ['code' => 'PHONE', 'name' => 'Phone'],
            ['code' => 'WALK_IN', 'name' => 'Walk-in'],
            ['code' => 'REFERRAL', 'name' => 'Referral'],
            ['code' => 'EXISTING_CUSTOMER', 'name' => 'Existing Customer'],
            ['code' => 'OTHER', 'name' => 'Other'],
        ];

        foreach ($rows as $i => $row) {
            LeadSource::updateOrCreate(
                ['name' => $row['name']],
                ['code' => $row['code'], 'is_system' => true, 'sort_order' => $i, 'is_active' => true],
            );
        }
    }
}
