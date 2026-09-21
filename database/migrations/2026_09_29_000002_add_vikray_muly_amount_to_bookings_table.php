<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The declared registry sale value ("Vikray Muly") for the Plot KYC Receipt.
 * Booking-level, not plot-level — unlike the plot's physical land records,
 * this is a transaction-specific declared value entered when preparing the
 * KYC receipt, independent of (and not necessarily equal to) the frozen
 * pricing snapshot in `bookings.final_amount`. Nullable: it is only ever set
 * once a Plot KYC Receipt is being prepared, and normal booking workflows
 * never require it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->decimal('vikray_muly_amount', 15, 2)->nullable()->after('final_amount');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn('vikray_muly_amount');
        });
    }
};
