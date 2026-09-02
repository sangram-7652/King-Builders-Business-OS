<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Possession case (M10) — one per booking (unique). `case_number` (POS-000001)
 * is unique + concurrency-safe. The case CONSUMES M6/M7/M8/M9 state via the
 * eligibility engine; it never modifies booking pricing or registry records.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('possession_cases', function (Blueprint $table) {
            $table->id();
            $table->string('case_number', 20)->unique();
            $table->foreignId('booking_id')->unique()->constrained('bookings')->cascadeOnDelete();
            $table->foreignId('plot_id')->constrained('plots')->cascadeOnDelete();
            $table->string('status', 24)->default('not_started')->index(); // App\Enums\PossessionCaseStatus

            $table->json('eligibility_snapshot')->nullable();
            $table->timestamp('eligibility_checked_at')->nullable();

            $table->timestamp('eligible_at')->nullable();
            $table->timestamp('initiated_at')->nullable();
            $table->foreignId('initiated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('scheduled_at')->nullable();
            $table->string('site_location')->nullable();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('inspection_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();

            // The POSSESSION_CERTIFICATE document slot on the booking (versioned).
            $table->foreignId('certificate_document_id')->nullable()->constrained('documents')->nullOnDelete();
            $table->timestamp('certificate_generated_at')->nullable();

            $table->string('hold_reason')->nullable();
            $table->string('status_before_hold', 24)->nullable();
            $table->string('cancellation_reason')->nullable();

            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('possession_cases');
    }
};
