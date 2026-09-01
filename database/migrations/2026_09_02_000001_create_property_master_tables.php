<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Property master data (M2). Consumed by the future Plots / Booking / Pricing
 * modules — no business tables are created here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plot_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code', 32)->unique();
            $table->string('description')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('plot_sizes', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->decimal('area', 12, 2);
            $table->string('unit', 16)->default('sq_ft');
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['area', 'unit']);
        });

        Schema::create('plot_dimensions', function (Blueprint $table) {
            $table->id();
            $table->string('display_name');
            $table->decimal('width', 10, 2);
            $table->decimal('length', 10, 2);
            $table->string('unit', 8)->default('ft');
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['width', 'length', 'unit']);
        });

        Schema::create('plc_types', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code', 32)->unique();
            $table->string('calculation_type', 24); // App\Enums\Masters\PlcCalculationType
            $table->decimal('value', 14, 2)->default(0);
            $table->string('description')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plc_types');
        Schema::dropIfExists('plot_dimensions');
        Schema::dropIfExists('plot_sizes');
        Schema::dropIfExists('plot_categories');
    }
};
