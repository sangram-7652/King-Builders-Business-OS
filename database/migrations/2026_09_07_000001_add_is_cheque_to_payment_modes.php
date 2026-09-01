<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Flags which payment-mode master rows are cheques, so the payment pipeline
 * (M7) knows when to require cheque metadata and run the cheque lifecycle —
 * without hard-coding the "CHEQUE" code anywhere.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_modes', function (Blueprint $table) {
            $table->boolean('is_cheque')->default(false)->after('requires_reference');
        });
    }

    public function down(): void
    {
        Schema::table('payment_modes', function (Blueprint $table) {
            $table->dropColumn('is_cheque');
        });
    }
};
