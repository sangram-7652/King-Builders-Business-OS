<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only possession / transfer / ownership timeline (M10) — the same
 * lightweight activity pattern as M5 `lead_activities`, M8
 * `collection_activities` and M9 `document_activities`, not a second audit
 * system.
 *
 * `booking_id` / `plot_id` / `buyer_id` are all nullable so an event can be
 * queried from whichever 360 view it belongs to. Never store PII in
 * `properties`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('possession_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->nullable()->constrained('bookings')->cascadeOnDelete();
            $table->foreignId('plot_id')->nullable()->constrained('plots')->cascadeOnDelete();
            $table->foreignId('buyer_id')->nullable()->constrained('buyers')->cascadeOnDelete();
            $table->string('type', 40); // App\Enums\PossessionActivityType
            $table->string('description');
            $table->json('properties')->nullable();
            $table->foreignId('causer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable()->index();

            $table->index(['booking_id', 'id']);
            $table->index(['plot_id', 'id']);
            $table->index(['buyer_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('possession_activities');
    }
};
