<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Other charges" master (M6): development, maintenance, documentation, legal…
 * Names are NOT hard-coded anywhere — the booking pricing engine reads this
 * table. Each row carries how it is calculated (fixed / percentage / per sq ft)
 * and its rate; a booking snapshots the applied value onto a price line.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('charge_types', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('code', 32)->unique();
            $table->string('calculation_type', 16); // App\Enums\PriceCalculationType
            $table->decimal('value', 15, 4); // amount / percentage / rate per sq ft
            $table->string('description')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('charge_types');
    }
};
