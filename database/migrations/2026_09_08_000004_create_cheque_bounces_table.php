<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cheque bounce record (M8) — metadata on a bounced cheque payment. It builds
 * on the M7 cheque support: the underlying payment is FAILED (bounce while
 * pending) or REVERSED (bounce after clearing) through M7 — never deleted,
 * never silently nulled. One bounce record per payment.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cheque_bounces', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->unique()->constrained('payments')->cascadeOnDelete();
            $table->foreignId('booking_id')->constrained('bookings')->cascadeOnDelete();
            $table->foreignId('collection_case_id')->nullable()->constrained('collection_cases')->nullOnDelete();

            $table->date('bounce_date');
            $table->string('bounce_reason');
            $table->decimal('bank_charges', 15, 2)->default(0);
            $table->text('notes')->nullable();
            $table->boolean('payment_was_cleared')->default(false); // true = M7 reversal path

            $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cheque_bounces');
    }
};
