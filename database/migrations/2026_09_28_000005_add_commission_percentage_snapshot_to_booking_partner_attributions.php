<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Snapshots the promoter's commission % onto the attribution row at the
 * moment the promoter is attached to a booking (F-M14-ADV audit finding).
 *
 * Without this, CommissionCaseWriter read Partner.commission_percentage LIVE
 * at every (re)calculation, so a later change to the promoter's master rate
 * would silently drift an already-attributed-but-not-yet-approved booking's
 * commission — violating "a booking's commission rate is fixed at the moment
 * the promoter is attached to it; changing the promoter's master rate later
 * must never recalculate historical/in-flight bookings."
 *
 * Nullable so existing rows (attributed before this fix) fall back to the
 * partner's live rate — no backfill invented, no historical data touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('booking_partner_attributions', function (Blueprint $table) {
            $table->decimal('commission_percentage', 5, 2)->nullable()->after('partner_id');
        });
    }

    public function down(): void
    {
        Schema::table('booking_partner_attributions', function (Blueprint $table) {
            $table->dropColumn('commission_percentage');
        });
    }
};
