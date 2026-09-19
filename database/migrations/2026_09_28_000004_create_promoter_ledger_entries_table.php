<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A promoter's append-only financial ledger (advance + commission history).
 * Never edited or deleted — every movement is a new row. The advance balance
 * is always DERIVED from this table (see PromoterLedgerService::advanceBalance()),
 * never stored as the sole source of truth anywhere else.
 *
 * One row per movement:
 *   advance_given               — advance paid to the promoter (balance +)
 *   advance_refunded            — manual reduction / refund (balance -)
 *   commission                  — a commission was earned on a booking; carries
 *                                  gross / advance-adjusted / payable together
 *                                  (balance - by advance_adjusted_amount)
 *   advance_adjustment_reversed — compensates a `commission` row's adjustment
 *                                  when that commission is cancelled / reversed
 *                                  or superseded by a recalculation (balance +)
 *   commission_payout_recorded  — informational mirror of a recorded payout
 *   commission_payout_voided    — informational mirror of a voided payout
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promoter_ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('partner_id')->constrained('partners')->cascadeOnDelete();
            $table->string('type', 32); // App\Enums\PromoterLedgerEntryType

            $table->foreignId('booking_id')->nullable()->constrained('bookings')->nullOnDelete();
            $table->foreignId('commission_case_id')->nullable()->constrained('commission_cases')->nullOnDelete();
            $table->foreignId('commission_payout_id')->nullable()->constrained('commission_payouts')->nullOnDelete();

            $table->decimal('gross_commission_amount', 15, 2)->nullable();
            $table->decimal('advance_amount', 15, 2)->nullable();
            $table->decimal('adjustment_amount', 15, 2)->nullable();
            $table->decimal('payable_amount', 15, 2)->nullable();
            $table->decimal('payout_amount', 15, 2)->nullable();

            $table->decimal('balance_after', 15, 2);

            $table->string('reference')->nullable();
            $table->text('description')->nullable();

            // Only set on a `commission` row once a compensating
            // advance_adjustment_reversed row has been written for it.
            $table->timestamp('reversed_at')->nullable();
            $table->foreignId('reversal_entry_id')->nullable()->constrained('promoter_ledger_entries')->nullOnDelete();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['partner_id', 'id']);
            $table->index(['commission_case_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promoter_ledger_entries');
    }
};
