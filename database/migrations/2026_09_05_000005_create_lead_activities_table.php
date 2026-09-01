<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only lead activity timeline (M5) — the lightweight audit foundation
 * from docs/CONVENTIONS.md §9. Never store PII in `properties`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained('leads')->cascadeOnDelete();
            $table->string('type', 32); // App\Enums\LeadActivityType
            $table->string('description');
            $table->json('properties')->nullable();
            $table->foreignId('causer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable()->index();

            $table->index(['lead_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_activities');
    }
};
