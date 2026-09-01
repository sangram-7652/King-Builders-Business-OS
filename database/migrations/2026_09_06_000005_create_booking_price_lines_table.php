<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The itemised price breakdown of a booking (M6). One row per component
 * (BASE, PLC, CHARGE, DISCOUNT, TAX). `amount` is the resolved rupee figure
 * the pricing engine computed — always a snapshot, never recalculated from the
 * masters once a booking is confirmed.
 *
 * The nullable master FKs (`plc_type_id`, `charge_type_id`, `tax_rate_id`) are
 * kept for reporting and to block hard-deleting a referenced master; the line
 * stays financially self-contained through `calculation_type` / `rate` /
 * `quantity` / `amount` even if the master later changes or is removed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_price_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained('bookings')->cascadeOnDelete();

            $table->string('type', 16);             // App\Enums\PriceComponentType
            $table->string('name');
            $table->string('calculation_type', 16); // App\Enums\PriceCalculationType
            $table->decimal('quantity', 15, 4)->nullable(); // e.g. area for PER_SQFT
            $table->decimal('rate', 15, 4)->default(0);      // ₹ amount / ₹ per sq ft / %
            $table->decimal('amount', 15, 2)->default(0);    // resolved figure

            $table->foreignId('plc_type_id')->nullable()->constrained('plc_types')->nullOnDelete();
            $table->foreignId('charge_type_id')->nullable()->constrained('charge_types')->nullOnDelete();
            $table->foreignId('tax_rate_id')->nullable()->constrained('tax_rates')->nullOnDelete();

            $table->unsignedInteger('sort_order')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['booking_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_price_lines');
    }
};
