<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Plot KYC Receipt's "Registry Buyer" — the name/mobile/address/PAN that
 * appear on the Registry KYC document, which may differ from the booking's
 * actual buyer (e.g. a spouse or nominee is the one named in the registry).
 * Booking-level and receipt-specific, exactly like `vikray_muly_amount`
 * (see `2026_09_29_000002_add_vikray_muly_amount_to_bookings_table.php`) —
 * not a change to `booking_buyers` or the `Buyer` master, and never written
 * to by anything except the Plot KYC Receipt Generate/Regenerate form.
 * Nullable: left unset, the receipt falls back to the booking's actual
 * primary buyer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->string('registry_buyer_name')->nullable()->after('vikray_muly_amount');
            $table->string('registry_buyer_mobile', 20)->nullable()->after('registry_buyer_name');
            $table->string('registry_buyer_address')->nullable()->after('registry_buyer_mobile');
            $table->string('registry_buyer_pan', 20)->nullable()->after('registry_buyer_address');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn(['registry_buyer_name', 'registry_buyer_mobile', 'registry_buyer_address', 'registry_buyer_pan']);
        });
    }
};
