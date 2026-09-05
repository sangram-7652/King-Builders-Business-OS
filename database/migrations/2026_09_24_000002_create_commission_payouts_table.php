<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Operational tracking of commission payouts against a case (M14.5). NOT an
 * accounting ledger — no double entry, no GST/TDS. A voided row is kept for the
 * audit trail and stops counting toward `commission_cases.paid_amount`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commission_payouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('commission_case_id')->constrained('commission_cases')->cascadeOnDelete();
            $table->decimal('amount', 15, 2);
            $table->string('method', 20);                 // App\Enums\CommissionPayoutMethod
            $table->date('paid_on');
            $table->string('reference')->nullable();
            $table->string('notes')->nullable();
            $table->string('status', 12)->default('recorded'); // App\Enums\CommissionPayoutStatus

            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('void_reason')->nullable();
            $table->timestamps();

            $table->index(['commission_case_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_payouts');
    }
};
