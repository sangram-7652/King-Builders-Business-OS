<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Land-record fields for the Plot KYC Receipt. Plot-level because a village /
 * survey (Gata) number and the four physical boundaries describe the land
 * parcel itself, not a transaction — the same as `area` / `facing`, these are
 * permanent attributes of the plot regardless of which booking is active.
 *
 * All nullable: existing plots have none of this recorded, and normal booking
 * / plot workflows must not be blocked by its absence (see PlotForm — these
 * fields are optional on the plot form too).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plots', function (Blueprint $table) {
            $table->string('village_name')->nullable()->after('facing');
            $table->string('gata_number', 64)->nullable()->after('village_name');
            $table->string('boundary_east')->nullable()->after('gata_number');
            $table->string('boundary_west')->nullable()->after('boundary_east');
            $table->string('boundary_north')->nullable()->after('boundary_west');
            $table->string('boundary_south')->nullable()->after('boundary_north');
        });
    }

    public function down(): void
    {
        Schema::table('plots', function (Blueprint $table) {
            $table->dropColumn(['village_name', 'gata_number', 'boundary_east', 'boundary_west', 'boundary_north', 'boundary_south']);
        });
    }
};
