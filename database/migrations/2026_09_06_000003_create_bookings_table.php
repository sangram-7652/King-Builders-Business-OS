<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Booking (M6) — the financial / inventory boundary.
 *
 * A confirmed booking is historical financial truth: it records WHO (booking_buyers),
 * WHAT PLOT, WHEN (booking_date / confirmed_at), AT WHAT PRICE (the money columns
 * + booking_price_lines + pricing_snapshot) and UNDER WHICH AUTHORISATION
 * (created_by / confirmed_by / price_override_*).
 *
 * `booking_number` (BK-000001) is the business identifier — unique, generated
 * via the code_sequences counter — NOT the primary key.
 *
 * `active_plot_id` is a STORED generated column: it equals plot_id only while
 * the booking reserves the plot (PENDING / CONFIRMED) and is NULL otherwise. A
 * UNIQUE index on it is the database-level guarantee that a plot can have at
 * most one live booking, regardless of application races. (Defined inside
 * CREATE TABLE — SQLite cannot add a STORED generated column via ALTER.)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->string('booking_number', 20)->unique();

            $table->foreignId('project_id')->constrained('projects')->restrictOnDelete();
            $table->foreignId('block_id')->constrained('blocks')->restrictOnDelete();
            $table->foreignId('plot_id')->constrained('plots')->restrictOnDelete();

            $table->date('booking_date');
            $table->string('status', 16)->default('draft')->index(); // App\Enums\BookingStatus

            // One live booking per plot — see class docblock.
            $table->unsignedBigInteger('active_plot_id')->nullable()
                ->storedAs("case when status in ('pending', 'confirmed') then plot_id else null end");

            // --- Applied pricing (snapshot fields) --------------------------
            $table->decimal('base_area', 15, 4)->default(0);   // sq ft used for the base
            $table->decimal('base_rate', 15, 4)->default(0);   // ₹ per sq ft
            $table->decimal('base_amount', 15, 2)->default(0); // base_area × base_rate
            $table->decimal('plc_amount', 15, 2)->default(0);
            $table->decimal('charge_amount', 15, 2)->default(0);
            $table->decimal('subtotal', 15, 2)->default(0);        // base + plc + charges
            $table->decimal('discount_amount', 15, 2)->default(0);
            $table->decimal('tax_amount', 15, 2)->default(0);
            $table->decimal('final_amount', 15, 2)->default(0);
            $table->json('pricing_snapshot')->nullable(); // frozen breakdown, set on confirm

            $table->text('notes')->nullable();

            // --- Authorisation trail --------------------------------------
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cancellation_reason')->nullable();

            // --- Manual price override -----------------------------------
            $table->boolean('price_overridden')->default(false);
            $table->foreignId('price_override_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('price_override_at')->nullable();
            $table->string('price_override_reason')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique('active_plot_id');
            $table->index(['plot_id', 'status']);
            $table->index('booking_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bookings');
    }
};
