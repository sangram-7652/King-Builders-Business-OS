<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payments (M7). `payment_number` (PAY-000001) is the public identifier —
 * unique, concurrency-safe, NOT the primary key.
 *
 * Financial truth flows Payment → PaymentAllocation → the booking's ledger; a
 * payment's effect on balances comes ONLY from its status being SUCCESS. Confirmed
 * payments are never deleted — they move to REVERSED with a reason.
 *
 * `idempotency_key` (nullable, unique) lets a duplicate "record payment"
 * request return the original payment instead of creating a second one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->string('payment_number', 20)->unique();
            $table->string('idempotency_key', 64)->nullable()->unique();

            $table->foreignId('booking_id')->constrained('bookings')->restrictOnDelete();
            $table->foreignId('payment_mode_id')->constrained('payment_modes')->restrictOnDelete();

            $table->date('payment_date');
            $table->decimal('amount', 15, 2);
            $table->string('status', 16)->default('pending')->index(); // App\Enums\PaymentStatus
            $table->string('reference_number')->nullable();
            $table->text('notes')->nullable();

            // Cheque metadata — only for cheque modes.
            $table->string('cheque_number', 64)->nullable();
            $table->string('cheque_bank_name')->nullable();
            $table->date('cheque_date')->nullable();
            $table->string('cheque_status', 16)->nullable(); // App\Enums\ChequeStatus

            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();

            $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reversed_at')->nullable();
            $table->string('reversal_reason')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['booking_id', 'status']);
            $table->index('payment_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
