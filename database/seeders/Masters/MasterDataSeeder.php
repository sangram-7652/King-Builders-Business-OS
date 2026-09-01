<?php

declare(strict_types=1);

namespace Database\Seeders\Masters;

use Illuminate\Database\Seeder;

/**
 * Seeds sensible initial values for every master. Each child seeder is
 * idempotent (`updateOrCreate` on a natural key) and safe to re-run.
 *
 * No fake business data (projects / plots / buyers / …) is ever seeded here.
 */
class MasterDataSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            PlotCategorySeeder::class,
            PlotSizeSeeder::class,
            PlotDimensionSeeder::class,
            PlcTypeSeeder::class,
            LeadSourceSeeder::class,
            TdsRuleSeeder::class,
            InterestRuleSeeder::class,
            PaymentTypeSeeder::class,
            PaymentModeSeeder::class,
            BankSeeder::class,
            BankBranchSeeder::class,
            StateSeeder::class,
            CitySeeder::class,
            DocumentTypeSeeder::class,
            CancellationReasonSeeder::class,
            TransferReasonSeeder::class,
        ]);
    }
}
