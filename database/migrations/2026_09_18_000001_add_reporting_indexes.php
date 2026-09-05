<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reporting performance indexes (M11.6 hardening).
 *
 * Both columns below are filtered as a date range on *every* report and export
 * (lead volume / conversion, MIS daily & monthly, collection demand series,
 * customer recovery) and neither is usable by an existing index:
 *
 *   - `leads.created_at`      — no index at all; every lead aggregate does
 *                               `WHERE created_at BETWEEN ? AND ?`
 *   - `installments.due_date` — only present as the *trailing* column of
 *                               `(payment_plan_id, due_date)`, so a base-table
 *                               `WHERE i.due_date < ?` / `BETWEEN` cannot seek it
 *
 * Additive, non-destructive. No other index was added: FK columns
 * (`project_id`, `block_id`, `plot_id`, `booking_id`, `created_by`, …) are
 * already indexed by their `constrained()` foreign keys, and
 * `bookings.booking_date` / `payments.payment_date` / every `status` column
 * already carry their own index.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table): void {
            $table->index('created_at', 'leads_created_at_index');
        });

        Schema::table('installments', function (Blueprint $table): void {
            $table->index('due_date', 'installments_due_date_index');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table): void {
            $table->dropIndex('leads_created_at_index');
        });

        Schema::table('installments', function (Blueprint $table): void {
            $table->dropIndex('installments_due_date_index');
        });
    }
};
