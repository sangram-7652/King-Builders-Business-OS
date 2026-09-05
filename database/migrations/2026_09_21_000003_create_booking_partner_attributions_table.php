<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Booking → channel-partner attribution with co-broker split (M14.2).
 *
 * The active set for a booking is the rows with `status = 'active'`
 * (`ended_at IS NULL`); their `share_percentage` always totals exactly 100.00
 * and exactly one row is `role = 'primary'`. Changing the split supersedes the
 * whole active set (status → 'superseded', `ended_at` stamped) and writes a new
 * set with the next `revision` — historical attribution is never mutated or
 * deleted, so an already-generated commission snapshot stays reproducible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_partner_attributions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained('bookings')->cascadeOnDelete();
            $table->foreignId('partner_id')->constrained('partners')->cascadeOnDelete();
            $table->decimal('share_percentage', 5, 2);           // 0.01 – 100.00
            $table->string('role', 16)->default('primary');      // App\Enums\BookingAttributionRole
            $table->string('status', 12)->default('active');     // active | superseded
            $table->unsignedInteger('revision')->default(1);

            $table->foreignId('attributed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('attributed_at');
            $table->timestamp('ended_at')->nullable();
            $table->string('reason')->nullable();
            $table->string('notes')->nullable();
            $table->timestamps();

            $table->index(['booking_id', 'status']);
            $table->index(['partner_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_partner_attributions');
    }
};
