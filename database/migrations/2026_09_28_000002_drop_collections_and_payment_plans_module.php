<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Decommissions the Collections module (M8), the Payment Plan / Installment
 * side of M7, and Payment Allocation — a client product decision: this CRM no
 * longer schedules installment plans, runs a dues/collections queue, or
 * tracks how a payment's money is applied. Direct Payments, Payment Ledger,
 * Verification, Reversal and Receipts (the rest of M7) are untouched — a
 * payment is recorded and verified directly against a booking, with no plan
 * and no allocation step. A booking's Paid amount is simply the sum of its
 * SUCCESS payments; Outstanding is `final_amount − Paid`.
 *
 * Verified against the live dev database before writing this migration:
 * `payment_allocations` had 0 rows, so dropping it loses no financial
 * history.
 *
 * Drop order respects foreign keys: collection tables that reference other
 * collection tables first, then `payment_allocations` (which referenced
 * `installments`), then `installments`, then `payment_plans`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('bounce_penalties');
        Schema::dropIfExists('collection_reminders');
        Schema::dropIfExists('collection_activities');
        Schema::dropIfExists('cheque_bounces');
        Schema::dropIfExists('payment_promises');
        Schema::dropIfExists('collection_follow_ups');
        Schema::dropIfExists('collection_cases');

        Schema::dropIfExists('payment_allocations');
        Schema::dropIfExists('installments');
        Schema::dropIfExists('payment_plans');
    }

    public function down(): void
    {
        Schema::create('payment_plans', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('booking_id')->constrained('bookings')->restrictOnDelete();
            $table->string('name')->default('Payment plan');
            $table->decimal('total_amount', 15, 2)->default(0);
            $table->string('status', 16)->default('draft')->index();
            $table->boolean('allows_variance')->default(false);
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

        Schema::create('installments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payment_plan_id')->constrained('payment_plans')->cascadeOnDelete();
            $table->unsignedInteger('installment_number');
            $table->string('name')->nullable();
            $table->date('due_date');
            $table->decimal('amount', 15, 2);
            $table->string('status', 20)->default('upcoming')->index();
            $table->timestamp('waived_at')->nullable();
            $table->foreignId('waived_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('waiver_reason')->nullable();
            $table->timestamps();

            $table->unique(['payment_plan_id', 'installment_number']);
            $table->index(['payment_plan_id', 'due_date'], 'installments_payment_plan_id_due_date_index');
            $table->index('due_date', 'installments_due_date_index');
        });

        Schema::create('payment_allocations', function (Blueprint $table): void {
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

        Schema::create('collection_cases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('booking_id')->unique()->constrained('bookings')->cascadeOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20)->default('open')->index();
            $table->string('priority', 12)->default('low')->index();
            $table->timestamp('opened_at')->useCurrent();
            $table->timestamp('last_follow_up_at')->nullable();
            $table->timestamp('next_follow_up_at')->nullable()->index();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('opened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['status', 'priority']);
            $table->index(['assigned_to', 'status']);
        });

        Schema::create('collection_follow_ups', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('collection_case_id')->constrained('collection_cases')->cascadeOnDelete();
            $table->foreignId('booking_id')->constrained('bookings')->cascadeOnDelete();
            $table->foreignId('installment_id')->nullable()->constrained('installments')->nullOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('follow_up_at');
            $table->string('outcome', 32)->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('next_follow_up_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('idempotency_key', 64)->nullable()->unique();
            $table->timestamps();

            $table->index(['collection_case_id', 'completed_at']);
            $table->index('follow_up_at');
        });

        Schema::create('payment_promises', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('collection_case_id')->constrained('collection_cases')->cascadeOnDelete();
            $table->foreignId('booking_id')->constrained('bookings')->cascadeOnDelete();
            $table->foreignId('installment_id')->nullable()->constrained('installments')->nullOnDelete();
            $table->decimal('promised_amount', 15, 2);
            $table->decimal('outstanding_at_creation', 15, 2);
            $table->date('promise_date');
            $table->string('status', 12)->default('open')->index();
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

        Schema::create('cheque_bounces', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payment_id')->unique()->constrained('payments')->cascadeOnDelete();
            $table->foreignId('booking_id')->constrained('bookings')->cascadeOnDelete();
            $table->foreignId('collection_case_id')->nullable()->constrained('collection_cases')->nullOnDelete();
            $table->date('bounce_date');
            $table->string('bounce_reason');
            $table->decimal('bank_charges', 15, 2)->default(0);
            $table->text('notes')->nullable();
            $table->boolean('payment_was_cleared')->default(false);
            $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('collection_activities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('collection_case_id')->constrained('collection_cases')->cascadeOnDelete();
            $table->foreignId('booking_id')->constrained('bookings')->cascadeOnDelete();
            $table->string('type', 32);
            $table->string('description');
            $table->json('properties')->nullable();
            $table->foreignId('causer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable()->index();

            $table->index(['collection_case_id', 'id']);
        });

        Schema::create('collection_reminders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('booking_id')->constrained('bookings')->cascadeOnDelete();
            $table->foreignId('collection_case_id')->nullable()->constrained('collection_cases')->nullOnDelete();
            $table->string('type', 24);
            $table->string('reference_type', 32)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->date('remind_on');
            $table->string('message');
            $table->string('status', 12)->default('pending')->index();
            $table->timestamp('seen_at')->nullable();
            $table->timestamps();

            $table->unique(['booking_id', 'type', 'reference_type', 'reference_id', 'remind_on'], 'collection_reminders_dedupe');
            $table->index(['status', 'remind_on']);
        });

        Schema::create('bounce_penalties', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cheque_bounce_id')->constrained('cheque_bounces')->cascadeOnDelete();
            $table->foreignId('booking_id')->constrained('bookings')->cascadeOnDelete();
            $table->decimal('penalty_amount', 15, 2);
            $table->string('reason');
            $table->string('status', 12)->default('assessed')->index();
            $table->foreignId('assessed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('assessed_at')->useCurrent();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancellation_reason')->nullable();
            $table->timestamps();
        });
    }
};
