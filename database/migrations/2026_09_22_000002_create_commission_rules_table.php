<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One calculation rule inside a commission scheme version (M14.3).
 *
 * `project_id IS NULL` is the scheme's default rule; a row with a `project_id`
 * overrides it for that project. `(commission_scheme_id, project_id)` is unique.
 * Frozen together with its scheme once the scheme is published.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commission_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('commission_scheme_id')->constrained('commission_schemes')->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained('projects')->cascadeOnDelete();
            $table->string('calc_type', 12);                 // App\Enums\CommissionCalcType
            $table->decimal('rate', 8, 4)->nullable();       // percentage, e.g. 2.5000
            $table->decimal('flat_amount', 15, 2)->nullable(); // fixed
            $table->string('slab_mode', 12)->nullable();     // App\Enums\SlabMode (slab only)
            $table->decimal('min_amount', 15, 2)->nullable(); // floor on the computed commission
            $table->decimal('max_amount', 15, 2)->nullable(); // cap on the computed commission
            $table->string('notes')->nullable();
            $table->timestamps();

            $table->unique(['commission_scheme_id', 'project_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_rules');
    }
};
