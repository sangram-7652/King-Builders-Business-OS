<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plot inventory (M4) — the leaf of Project → Block → Plot.
 *
 * Master references (category / size / dimension) are kept as FKs for
 * reporting, but `area` + `area_unit` are stored on the plot as a SNAPSHOT so a
 * later edit to a Plot Size master never silently rewrites historical plots.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plots', function (Blueprint $table) {
            $table->id();

            $table->foreignId('project_id')->constrained('projects')->restrictOnDelete();
            $table->foreignId('block_id')->constrained('blocks')->restrictOnDelete();
            $table->string('plot_number', 32);

            // Master references — restrict hard-delete of a referenced master.
            $table->foreignId('plot_category_id')->nullable()->constrained('plot_categories')->nullOnDelete();
            $table->foreignId('plot_size_id')->nullable()->constrained('plot_sizes')->nullOnDelete();
            $table->foreignId('plot_dimension_id')->nullable()->constrained('plot_dimensions')->nullOnDelete();

            // Applied values — snapshot, independent of the masters above.
            $table->decimal('area', 12, 2);
            $table->string('area_unit', 16); // App\Enums\Masters\AreaUnit
            $table->string('facing', 16)->nullable(); // App\Enums\PlotFacing

            $table->string('status', 16)->default('available')->index(); // App\Enums\PlotStatus
            $table->boolean('is_active')->default(true)->index();

            // Hold metadata — only populated while status = hold.
            $table->timestamp('held_at')->nullable();
            $table->timestamp('hold_expires_at')->nullable()->index();
            $table->string('hold_reason')->nullable();
            $table->foreignId('held_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['project_id', 'block_id', 'plot_number']);
            $table->index(['block_id', 'status']);
            $table->index(['project_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plots');
    }
};
