<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payment plan (M7) — the collection schedule for a confirmed booking's
 * financial obligation. `total_amount` must reconcile with the booking's
 * frozen `final_amount` (the M6 price snapshot is never altered here).
 *
 * `active_plan_booking_id` is a STORED generated column + UNIQUE index: a
 * booking has at most one live (DRAFT or ACTIVE) plan, DB-guaranteed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained('bookings')->restrictOnDelete();
            $table->string('name')->default('Payment plan');
            $table->decimal('total_amount', 15, 2)->default(0);
            $table->string('status', 16)->default('draft')->index(); // App\Enums\PaymentPlanStatus
            $table->boolean('allows_variance')->default(false); // total may differ from booking final_amount

            // One live plan per booking — see class docblock.
            $table->unsignedBigInteger('active_plan_booking_id')->nullable()
                ->storedAs("case when status in ('draft', 'active') then booking_id else null end");

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('activated_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cancellation_reason')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique('active_plan_booking_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_plans');
    }
};
