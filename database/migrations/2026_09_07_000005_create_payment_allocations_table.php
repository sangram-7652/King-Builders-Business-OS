<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payment allocations (M7) — how a payment's money is applied to installments.
 * A payment may split across several installments; each (payment, installment)
 * pair is unique so a retried allocation request can never double-count.
 *
 * Allocations are never deleted: when a payment is REVERSED its allocations
 * simply stop counting (the ledger only sums allocations of SUCCESS payments),
 * preserving the financial trail.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained('payments')->cascadeOnDelete();
            $table->foreignId('installment_id')->constrained('installments')->restrictOnDelete();
            $table->decimal('amount', 15, 2);
            $table->foreignId('allocated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_auto')->default(true);
            $table->timestamps();

            $table->unique(['payment_id', 'installment_id']);
            $table->index('installment_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_allocations');
    }
};
