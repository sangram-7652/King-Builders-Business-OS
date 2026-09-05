<?php

declare(strict_types=1);

use App\Services\Ownership\PlotOwnershipService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F-M10-1 / DB-2 — enforce the "no overlapping active ownership" invariant at
 * the database, not only in {@see PlotOwnershipService}.
 *
 * The domain rule is: a given buyer may hold at most ONE open ownership period
 * (`ended_at IS NULL`) for a given booking. A co-owned booking legitimately has
 * several open rows (one per co-owner); a closed period may repeat freely.
 *
 * Implemented with two VIRTUAL generated columns that are non-null only while
 * the period is open, plus a composite UNIQUE index. When a period is closed
 * both columns become NULL and — on both MySQL (InnoDB) and SQLite — a
 * composite unique index treats NULLs as always distinct, so history is never
 * blocked. VIRTUAL (not STORED) so the ALTER needs no table rebuild — the
 * source columns carry foreign keys. No PostgreSQL-only partial index.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plot_ownership_history', function (Blueprint $table): void {
            $table->unsignedBigInteger('open_booking_id')
                ->nullable()
                ->virtualAs('(case when `ended_at` is null then `booking_id` end)')
                ->after('ended_at');

            $table->unsignedBigInteger('open_buyer_id')
                ->nullable()
                ->virtualAs('(case when `ended_at` is null then `buyer_id` end)')
                ->after('open_booking_id');
        });

        Schema::table('plot_ownership_history', function (Blueprint $table): void {
            $table->unique(
                ['open_booking_id', 'open_buyer_id'],
                'plot_ownership_history_one_open_period_per_owner',
            );
        });
    }

    public function down(): void
    {
        Schema::table('plot_ownership_history', function (Blueprint $table): void {
            $table->dropUnique('plot_ownership_history_one_open_period_per_owner');
            $table->dropColumn(['open_booking_id', 'open_buyer_id']);
        });
    }
};
