<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lead → channel-partner attribution (M14.2). `partner_id` is the *current*
 * attributed partner, denormalised onto `leads` for filtering / reporting; the
 * full change history lives in `lead_partner_attributions`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->foreignId('partner_id')->nullable()->after('assigned_to')
                ->constrained('partners')->nullOnDelete();
            $table->index('partner_id');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropConstrainedForeignId('partner_id');
        });
    }
};
