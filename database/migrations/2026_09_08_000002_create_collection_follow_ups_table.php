<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Collection follow-up (M8) — a logged interaction chasing money. Deliberately
 * separate from M5 `lead_follow_ups` (that is sales-pipeline chasing).
 *
 * `idempotency_key` (nullable, unique) makes a retried "schedule follow-up"
 * request return the original row instead of creating a duplicate.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('collection_follow_ups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('collection_case_id')->constrained('collection_cases')->cascadeOnDelete();
            $table->foreignId('booking_id')->constrained('bookings')->cascadeOnDelete();
            $table->foreignId('installment_id')->nullable()->constrained('installments')->nullOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('follow_up_at');
            $table->string('outcome', 32)->nullable(); // App\Enums\CollectionFollowUpOutcome
            $table->text('notes')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('next_follow_up_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('idempotency_key', 64)->nullable()->unique();
            $table->timestamps();

            $table->index(['collection_case_id', 'completed_at']);
            $table->index('follow_up_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('collection_follow_ups');
    }
};
