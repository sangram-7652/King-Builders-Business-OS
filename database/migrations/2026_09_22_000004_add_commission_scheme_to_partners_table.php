<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A partner's assigned commission scheme (M14.3). Stored as the family `code`
 * (not an id) so it always resolves to whichever version of that scheme is
 * currently PUBLISHED — the actual version used is snapshotted onto the
 * commission case at calculation time (M14.4).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('partners', function (Blueprint $table) {
            $table->string('commission_scheme_code', 20)->nullable()->after('rera_number');
        });
    }

    public function down(): void
    {
        Schema::table('partners', function (Blueprint $table) {
            $table->dropColumn('commission_scheme_code');
        });
    }
};
