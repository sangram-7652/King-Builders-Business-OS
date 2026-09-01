<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only documentation / agreement / registry timeline (M9) — the same
 * lightweight activity pattern as M5 `lead_activities` and M8
 * `collection_activities`, not a second audit system.
 *
 * `booking_id` and `buyer_id` are both nullable so a buyer-KYC event and a
 * booking event can each be queried for their own timeline. Never store PII in
 * `properties`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->nullable()->constrained('bookings')->cascadeOnDelete();
            $table->foreignId('buyer_id')->nullable()->constrained('buyers')->cascadeOnDelete();
            $table->string('type', 40); // App\Enums\DocumentActivityType
            $table->string('description');
            $table->json('properties')->nullable();
            $table->foreignId('causer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable()->index();

            $table->index(['booking_id', 'id']);
            $table->index(['buyer_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_activities');
    }
};
