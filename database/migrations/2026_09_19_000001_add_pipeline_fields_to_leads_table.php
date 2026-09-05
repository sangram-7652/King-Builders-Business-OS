<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M13.1 — lead pipeline / next-action / response-time fields.
 *
 *  - first_contacted_at : set the first time the lead leaves NEW, or the first
 *    completed follow-up — the anchor for the M13.5 response-time report.
 *  - last_activity_at    : touched by every activity row — powers "hot lead
 *    inactive" reminders and the stale-lead sweep.
 *  - next_action / next_action_at : denormalised from the earliest PENDING
 *    follow-up so lists can sort/filter without a join.
 *
 * Additive, non-destructive.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table): void {
            // M5 sized this at 16 chars; the M13.1 pipeline adds
            // 'site_visit_planned' (17). Widen it (no-op on SQLite).
            $table->string('status', 24)->default('new')->change();

            $table->timestamp('first_contacted_at')->nullable()->after('status');
            $table->timestamp('last_activity_at')->nullable()->after('first_contacted_at');
            $table->string('next_action')->nullable()->after('last_activity_at');
            $table->timestamp('next_action_at')->nullable()->after('next_action');

            $table->index('next_action_at');
            $table->index('last_activity_at');
            $table->index(['assigned_to', 'status']);
        });

        // Backfill from what we already know so the columns are useful on day one.
        DB::table('leads')->update([
            'last_activity_at' => DB::raw('updated_at'),
            'next_action_at' => DB::raw('follow_up_at'),
        ]);

        DB::table('leads')->whereNotNull('converted_at')->update([
            'first_contacted_at' => DB::raw('coalesce(converted_at, created_at)'),
        ]);
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table): void {
            $table->dropIndex(['assigned_to', 'status']);
            $table->dropIndex(['last_activity_at']);
            $table->dropIndex(['next_action_at']);
            $table->dropColumn(['first_contacted_at', 'last_activity_at', 'next_action', 'next_action_at']);
        });
    }
};
