<?php

declare(strict_types=1);

namespace Database\Seeders\Masters;

use App\Models\Masters\PlotCategory;
use Illuminate\Database\Seeder;

class PlotCategorySeeder extends Seeder
{
    public function run(): void
    {
        $rows = [
            ['code' => 'RESI', 'name' => 'Residential', 'description' => 'Residential plots'],
            ['code' => 'COMM', 'name' => 'Commercial', 'description' => 'Commercial / shop plots'],
            ['code' => 'INST', 'name' => 'Institutional', 'description' => 'Institutional use'],
            ['code' => 'IND', 'name' => 'Industrial', 'description' => 'Industrial use'],
            ['code' => 'EWS', 'name' => 'EWS', 'description' => 'Economically weaker section'],
        ];

        foreach ($rows as $i => $row) {
            PlotCategory::updateOrCreate(
                ['code' => $row['code']],
                ['name' => $row['name'], 'description' => $row['description'], 'sort_order' => $i, 'is_active' => true],
            );
        }
    }
}
