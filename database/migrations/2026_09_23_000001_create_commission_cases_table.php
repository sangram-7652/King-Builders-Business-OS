<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A partner's commission obligation on one booking (M14.4). CMN-000001.
 * One case per (booking, partner) — `(booking_id, partner_id)` is unique, which
 * is also the guard against duplicate generation. The authoritative figures
 * live in the immutable `commission_calculations` snapshot the case points at;
 * the columns here are a denormalised copy for listing / filtering.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commission_cases', function (Blueprint $table) {
            $table->id();
            $table->string('case_number', 20)->unique();
            $table->foreignId('booking_id')->constrained('bookings')->cascadeOnDelete();
            $table->foreignId('partner_id')->constrained('partners')->cascadeOnDelete();
            $table->foreignId('booking_partner_attribution_id')->nullable()->constrained('booking_partner_attributions')->nullOnDelete();
            $table->foreignId('commission_scheme_id')->nullable()->constrained('commission_schemes')->nullOnDelete();
            $table->foreignId('commission_rule_id')->nullable()->constrained('commission_rules')->nullOnDelete();
            $table->unsignedBigInteger('current_calculation_id')->nullable(); // soft pointer into commission_calculations

            $table->string('status', 20)->default('pending_review')->index(); // App\Enums\CommissionCaseStatus

            $table->boolean('is_eligible')->default(true);
            $table->string('eligibility_reason')->nullable();
            $table->timestamp('eligibility_checked_at')->nullable();

            // Denormalised from the current calculation snapshot.
            $table->decimal('commission_amount', 15, 2)->default(0);
            $table->decimal('paid_amount', 15, 2)->default(0);

            $table->timestamp('generated_at')->nullable();
            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancellation_reason')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->string('reversal_reason')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['booking_id', 'partner_id']);
            $table->index(['partner_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_cases');
    }
};
