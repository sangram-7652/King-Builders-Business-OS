<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Promise to pay (M8). A promise is NOT a payment — it never touches the M7
 * ledger and never increases paid amount. It is marked KEPT only when an actual
 * SUCCESS M7 payment reduces the relevant outstanding by the promised amount;
 * BROKEN when its date passes unfulfilled.
 *
 * `outstanding_at_creation` snapshots the M7 outstanding at promise time so
 * "kept" can be evaluated against real payment progress.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_promises', function (Blueprint $table) {
            $table->id();
            $table->foreignId('collection_case_id')->constrained('collection_cases')->cascadeOnDelete();
            $table->foreignId('booking_id')->constrained('bookings')->cascadeOnDelete();
            $table->foreignId('installment_id')->nullable()->constrained('installments')->nullOnDelete();

            $table->decimal('promised_amount', 15, 2);
            $table->decimal('outstanding_at_creation', 15, 2);
            $table->date('promise_date');
            $table->string('status', 12)->default('open')->index(); // App\Enums\PromiseStatus
            $table->text('notes')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('fulfilled_at')->nullable();
            $table->foreignId('fulfilled_by_payment_id')->nullable()->constrained('payments')->nullOnDelete();
            $table->timestamp('broken_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cancellation_reason')->nullable();
            $table->string('idempotency_key', 64)->nullable()->unique();
            $table->timestamps();

            $table->index(['booking_id', 'status']);
            $table->index(['status', 'promise_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_promises');
    }
};
