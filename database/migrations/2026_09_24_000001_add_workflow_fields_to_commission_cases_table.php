<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Commission case review / hold / reversal fields (M14.5).
 * `clawback_amount` is what was already paid out and must be recovered
 * operationally when an approved/paid case is reversed — tracking only, not a
 * ledger entry.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commission_cases', function (Blueprint $table) {
            $table->timestamp('approved_at')->nullable()->after('generated_by');
            $table->foreignId('approved_by')->nullable()->after('approved_at')->constrained('users')->nullOnDelete();
            $table->timestamp('held_at')->nullable()->after('approved_by');
            $table->string('hold_reason')->nullable()->after('held_at');
            $table->decimal('clawback_amount', 15, 2)->default(0)->after('paid_amount');
        });
    }

    public function down(): void
    {
        Schema::table('commission_cases', function (Blueprint $table) {
            $table->dropConstrainedForeignId('approved_by');
            $table->dropColumn(['approved_at', 'held_at', 'hold_reason', 'clawback_amount']);
        });
    }
};
