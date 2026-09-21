<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marks which document types may hold several independent files per
 * documentable (see the companion `documents.sequence` migration). Defaults
 * to false for every existing type — nothing changes until a type is
 * explicitly opted in (Payment Documents / Registry Documents, via the
 * seeder).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_types', function (Blueprint $table): void {
            $table->boolean('allows_multiple')->default(false)->after('default_required');
        });
    }

    public function down(): void
    {
        Schema::table('document_types', function (Blueprint $table): void {
            $table->dropColumn('allows_multiple');
        });
    }
};
