<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M9 tags each M2 document-type master with which entity it applies to (buyer
 * vs booking) and whether it is required by default, so the requirement /
 * checklist engine has a sensible baseline without hard-coding anything.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_types', function (Blueprint $table) {
            $table->string('applies_to', 12)->default('booking')->after('code'); // App\Enums\DocumentScope
            $table->boolean('default_required')->default(false)->after('applies_to');
            $table->boolean('supports_expiry')->default(false)->after('default_required');
        });
    }

    public function down(): void
    {
        Schema::table('document_types', function (Blueprint $table) {
            $table->dropColumn(['applies_to', 'default_required', 'supports_expiry']);
        });
    }
};
