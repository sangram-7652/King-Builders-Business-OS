<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only collection timeline (M8) — the same lightweight activity pattern
 * as M5 `lead_activities`, not a second audit system. Never store PII in
 * `properties`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('collection_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('collection_case_id')->constrained('collection_cases')->cascadeOnDelete();
            $table->foreignId('booking_id')->constrained('bookings')->cascadeOnDelete();
            $table->string('type', 32); // App\Enums\CollectionActivityType
            $table->string('description');
            $table->json('properties')->nullable();
            $table->foreignId('causer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable()->index();

            $table->index(['collection_case_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('collection_activities');
    }
};
