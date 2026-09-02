<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ownership / nominee transfer request (M10). `request_number` (TRF-000001) is
 * unique + concurrency-safe. Completion is transactional and is the ONLY thing
 * that mutates `plot_ownership_history`. Historical buyer / booking_buyer rows
 * are never mutated to represent a transfer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transfer_requests', function (Blueprint $table) {
            $table->id();
            $table->string('request_number', 20)->unique();
            $table->foreignId('booking_id')->constrained('bookings')->cascadeOnDelete();
            $table->foreignId('plot_id')->constrained('plots')->cascadeOnDelete();
            $table->string('transfer_type', 24); // App\Enums\TransferType
            $table->string('status', 24)->default('draft')->index(); // App\Enums\TransferRequestStatus

            $table->foreignId('current_buyer_id')->nullable()->constrained('buyers')->nullOnDelete();
            $table->foreignId('new_buyer_id')->nullable()->constrained('buyers')->nullOnDelete();

            $table->string('reason')->nullable();
            $table->json('financial_snapshot')->nullable(); // outstanding / overdue at review time
            $table->string('financial_waiver_reason')->nullable();

            $table->timestamp('requested_at')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('review_started_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('rejection_reason')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancellation_reason')->nullable();

            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['booking_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transfer_requests');
    }
};
