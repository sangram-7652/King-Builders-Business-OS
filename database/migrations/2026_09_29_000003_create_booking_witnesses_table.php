<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Booking-level witnesses (Plot KYC Receipt, "Witness 1" / "Witness 2") —
 * witnesses belong to a specific registry transaction, not to the plot or
 * buyer, so this is scoped to `bookings`, not a new global entity. Entirely
 * optional: a booking with no rows here simply prints blank witness fields —
 * normal booking / registry workflows never require them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_witnesses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained('bookings')->cascadeOnDelete();
            $table->unsignedTinyInteger('witness_number'); // 1 or 2
            $table->string('name');
            $table->string('address')->nullable();
            $table->string('mobile', 20)->nullable();
            $table->timestamps();

            $table->unique(['booking_id', 'witness_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_witnesses');
    }
};
