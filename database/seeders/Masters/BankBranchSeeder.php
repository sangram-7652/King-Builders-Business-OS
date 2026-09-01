<?php

declare(strict_types=1);

namespace Database\Seeders\Masters;

use App\Models\Masters\Bank;
use App\Models\Masters\BankBranch;
use Illuminate\Database\Seeder;

class BankBranchSeeder extends Seeder
{
    public function run(): void
    {
        // One illustrative branch per seeded bank (real branch lists are imported later).
        $branches = [
            'SBI' => ['name' => 'Main Branch', 'ifsc' => 'SBIN0000001'],
            'HDFC' => ['name' => 'Main Branch', 'ifsc' => 'HDFC0000001'],
            'ICICI' => ['name' => 'Main Branch', 'ifsc' => 'ICIC0000001'],
        ];

        foreach ($branches as $bankCode => $branch) {
            $bank = Bank::where('code', $bankCode)->first();

            if ($bank === null) {
                continue;
            }

            BankBranch::updateOrCreate(
                ['bank_id' => $bank->id, 'name' => $branch['name']],
                ['ifsc' => $branch['ifsc'], 'sort_order' => 0, 'is_active' => true],
            );
        }
    }
}
