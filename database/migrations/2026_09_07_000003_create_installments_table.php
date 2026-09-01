<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Installments (M7) — the individual due lines of a payment plan. `amount`
 * values reconcile EXACTLY with the plan total (deterministic rounding, the
 * last installment absorbs the remainder). `status` is derived from the ledger
 * and re-computed after every allocation / reversal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('installments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_plan_id')->constrained('payment_plans')->cascadeOnDelete();
            $table->unsignedInteger('installment_number');
            $table->string('name')->nullable();
            $table->date('due_date');
            $table->decimal('amount', 15, 2);
            $table->string('status', 20)->default('upcoming')->index(); // App\Enums\InstallmentStatus
            $table->timestamp('waived_at')->nullable();
            $table->foreignId('waived_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('waiver_reason')->nullable();
            $table->timestamps();

            $table->unique(['payment_plan_id', 'installment_number']);
            $table->index(['payment_plan_id', 'due_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('installments');
    }
};
