<?php

declare(strict_types=1);

namespace Database\Seeders\Masters;

use App\Models\Masters\City;
use App\Models\Masters\State;
use Illuminate\Database\Seeder;

class CitySeeder extends Seeder
{
    public function run(): void
    {
        // A starter set of major cities per state — extended via import / UI later.
        $citiesByState = [
            'DL' => ['New Delhi'],
            'HR' => ['Gurugram', 'Faridabad', 'Panipat', 'Karnal', 'Hisar'],
            'UP' => ['Noida', 'Ghaziabad', 'Lucknow', 'Kanpur', 'Agra', 'Meerut'],
            'MH' => ['Mumbai', 'Pune', 'Nagpur', 'Nashik', 'Thane'],
            'KA' => ['Bengaluru', 'Mysuru', 'Mangaluru'],
            'TN' => ['Chennai', 'Coimbatore', 'Madurai'],
            'TG' => ['Hyderabad', 'Warangal'],
            'GJ' => ['Ahmedabad', 'Surat', 'Vadodara', 'Rajkot'],
            'RJ' => ['Jaipur', 'Jodhpur', 'Udaipur', 'Kota'],
            'PB' => ['Ludhiana', 'Amritsar', 'Jalandhar', 'Mohali'],
            'WB' => ['Kolkata', 'Howrah', 'Siliguri'],
            'MP' => ['Indore', 'Bhopal', 'Gwalior', 'Jabalpur'],
        ];

        foreach ($citiesByState as $stateCode => $cities) {
            $state = State::where('code', $stateCode)->first();

            if ($state === null) {
                continue;
            }

            foreach (array_values($cities) as $i => $cityName) {
                City::updateOrCreate(
                    ['state_id' => $state->id, 'name' => $cityName],
                    ['sort_order' => $i, 'is_active' => true],
                );
            }
        }
    }
}
