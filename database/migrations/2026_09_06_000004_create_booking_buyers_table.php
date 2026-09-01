<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Booking ↔ Buyer association (M6). A booking is co-owned by one or more
 * buyers; `buyer_id` is deliberately NOT a column on `bookings`.
 *
 * Rules (enforced in the domain layer, not just here):
 *  - at least one buyer per booking
 *  - exactly one `is_primary` buyer
 *  - `ownership_percentage` values are each > 0 and ≤ 100 and sum to EXACTLY 100
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_buyers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained('bookings')->cascadeOnDelete();
            $table->foreignId('buyer_id')->constrained('buyers')->restrictOnDelete();
            $table->decimal('ownership_percentage', 5, 2);
            $table->boolean('is_primary')->default(false);
            $table->timestamps();

            $table->unique(['booking_id', 'buyer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_buyers');
    }
};
