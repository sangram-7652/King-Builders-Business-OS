<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Receipts (M7). `receipt_number` (RCPT-000001) is unique + concurrency-safe.
 * One receipt per payment (unique `payment_id`), issued automatically when a
 * payment is verified SUCCESS.
 *
 * The receipt snapshots the buyer name, payment mode label and amount at issue
 * time so a later master edit never rewrites a printed receipt. A reversed
 * payment's receipt is voided (kept, marked), never deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('receipts', function (Blueprint $table) {
            $table->id();
            $table->string('receipt_number', 20)->unique();
            $table->foreignId('payment_id')->unique()->constrained('payments')->restrictOnDelete();
            $table->foreignId('booking_id')->constrained('bookings')->restrictOnDelete();
            $table->foreignId('buyer_id')->nullable()->constrained('buyers')->nullOnDelete();

            $table->decimal('amount', 15, 2);
            $table->date('payment_date');
            $table->string('payment_mode_label');
            $table->string('reference_number')->nullable();
            $table->string('buyer_name_snapshot');

            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('issued_at');

            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('void_reason')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('receipts');
    }
};
