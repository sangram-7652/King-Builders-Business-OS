<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The simplified Registry status ("client does not want a Registry Case
 * workflow — Registry is simply PENDING or DONE"). Booking-scoped: this is
 * the ONE authoritative Registry status going forward — see
 * App\Enums\RegistryStatus and App\Actions\Registry\MarkRegistryDoneAction.
 * The legacy `registry_cases` table / RegistryCaseStatus are NOT touched by
 * this migration and are NOT written to by the new workflow; they remain
 * purely as historical data for any booking that already has one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->string('registry_status', 16)->default('pending')->after('registry_buyer_pan');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn('registry_status');
        });
    }
};
