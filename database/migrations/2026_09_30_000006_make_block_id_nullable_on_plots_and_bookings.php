<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Makes Block OPTIONAL in the Project → Block → Plot hierarchy (product
 * requirement: a Project can also hold plots directly, with no Block).
 *
 *   plots.project_id   — unchanged, still required
 *   plots.block_id      — NOT NULL -> NULLABLE (a NULL means "direct project plot")
 *   bookings.block_id   — NOT NULL -> NULLABLE (mirrors the booking's plot)
 *
 * The existing FK constraints (restrictOnDelete) are left completely
 * untouched — a NULL value never participates in a foreign key check, and
 * every EXISTING row's block_id value is left exactly as it is. No data is
 * moved, copied or deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plots', function (Blueprint $table) {
            $table->foreignId('block_id')->nullable()->change();
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->foreignId('block_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('plots', function (Blueprint $table) {
            $table->foreignId('block_id')->nullable(false)->change();
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->foreignId('block_id')->nullable(false)->change();
        });
    }
};
