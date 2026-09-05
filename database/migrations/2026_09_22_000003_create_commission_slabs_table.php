<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A single bracket of a SLAB commission rule (M14.3). Brackets are contiguous
 * and non-overlapping, ordered by `from_amount`; the last one's `to_amount`
 * IS NULL (open-ended). Frozen with its rule once the scheme is published.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commission_slabs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('commission_rule_id')->constrained('commission_rules')->cascadeOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->decimal('from_amount', 15, 2)->default(0);
            $table->decimal('to_amount', 15, 2)->nullable();  // null = open-ended
            $table->string('calc_type', 12);                  // percentage | fixed
            $table->decimal('rate', 8, 4)->nullable();
            $table->decimal('flat_amount', 15, 2)->nullable();
            $table->timestamps();

            $table->index(['commission_rule_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_slabs');
    }
};
