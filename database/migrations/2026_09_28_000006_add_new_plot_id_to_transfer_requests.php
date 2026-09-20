<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Supports the PLOT_TRANSFER type (M10 extension): a booking's plot changes
 * while the buyer stays the same. `plot_id` continues to mean "the plot at
 * request time" (the OLD plot, for a plot transfer — unchanged for every
 * other transfer type); `new_plot_id` is only set for a plot transfer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transfer_requests', function (Blueprint $table) {
            $table->foreignId('new_plot_id')->nullable()->after('plot_id')
                ->constrained('plots')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('transfer_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('new_plot_id');
        });
    }
};
