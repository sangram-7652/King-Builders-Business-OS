<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tax rate master (M6) — calculation foundation only. No filing, no returns,
 * no jurisdiction engine. A tax rate is a named percentage applied to the
 * taxable amount (subtotal − discount). Rates are configurable; nothing in the
 * engine assumes a fixed figure.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_rates', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('code', 32)->unique();
            $table->decimal('percentage', 8, 4); // e.g. 5.0000, 18.0000
            $table->string('description')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_rates');
    }
};
