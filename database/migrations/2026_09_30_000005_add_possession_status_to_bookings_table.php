<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The simplified Possession status ("client does not want a Possession Case
 * / eligibility workflow — Possession is simply PENDING or DONE"). Booking-
 * scoped: this is the ONE authoritative Possession status going forward —
 * see App\Enums\PossessionStatus and App\Actions\Possession\MarkPossessionDoneAction.
 * The legacy `possession_cases` table / PossessionCaseStatus are NOT touched
 * by this migration and are NOT written to by the new workflow; they remain
 * purely as historical data for any booking that already has one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->string('possession_status', 16)->default('pending')->after('registry_status');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn('possession_status');
        });
    }
};
