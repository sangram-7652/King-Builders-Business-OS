<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only plot ownership ledger (M10). The ORIGINAL allotment period(s) are
 * derived from `booking_buyers`; a completed transfer closes the active
 * period(s) (`ended_at`) and opens new ones. Nothing here is ever deleted or
 * mutated destructively — `ended_at` NULL marks the current owner(s).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plot_ownership_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plot_id')->constrained('plots')->cascadeOnDelete();
            $table->foreignId('booking_id')->constrained('bookings')->cascadeOnDelete();
            $table->foreignId('buyer_id')->constrained('buyers')->cascadeOnDelete();
            $table->string('ownership_type', 16); // App\Enums\OwnershipType
            $table->boolean('is_primary')->default(false);
            $table->decimal('ownership_percentage', 5, 2)->default(100);

            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();

            // What created this period: booking (allotment) | transfer_request.
            $table->string('source_type', 32)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();

            $table->string('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['plot_id', 'ended_at']);
            $table->index(['buyer_id', 'ended_at']);
            $table->index(['booking_id', 'ended_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plot_ownership_history');
    }
};
