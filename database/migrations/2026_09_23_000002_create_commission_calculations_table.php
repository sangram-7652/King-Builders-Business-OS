<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Immutable commission calculation snapshot (M14.4). Append-only — one row per
 * calculation run of a case (`sequence` 1, 2, 3…). A row is NEVER updated once
 * written, so a figure that was approved / paid stays reproducible even after
 * the commission scheme is re-versioned. Recalculating a case writes a new row
 * and moves `commission_cases.current_calculation_id`.
 *
 * `snapshot` holds the full frozen detail (scheme code+version, basis figure
 * and its M6/M7 source, rule config, slab breakdown, share split).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commission_calculations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('commission_case_id')->constrained('commission_cases')->cascadeOnDelete();
            $table->unsignedInteger('sequence')->default(1);

            $table->string('scheme_code', 20);
            $table->unsignedInteger('scheme_version');
            $table->string('basis', 24);                  // App\Enums\CommissionBasis
            $table->decimal('basis_amount', 15, 2);       // the M6/M7 figure at calc time
            $table->decimal('share_percentage', 5, 2);
            $table->string('calc_type', 12);              // App\Enums\CommissionCalcType

            $table->decimal('gross_before_caps', 15, 4);  // rule applied to full basis, pre floor/cap
            $table->decimal('gross_amount', 15, 2);       // after rule min/max
            $table->decimal('commission_amount', 15, 2);  // partner's share = gross_amount * share%

            $table->json('snapshot');

            $table->timestamp('calculated_at');
            $table->foreignId('calculated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->unique(['commission_case_id', 'sequence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_calculations');
    }
};
