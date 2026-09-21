<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets a document TYPE opt into holding several independent files for the
 * same documentable (e.g. Payment Documents, Registry Documents on a
 * booking) instead of the usual one-Document-per-slot rule.
 *
 * `sequence` distinguishes multiple Document rows that would otherwise
 * collide on `(documentable_type, documentable_id, document_type_id)`.
 * Single-slot types (Booking Form, buyer KYC, …) always get `sequence = 1`,
 * so `documents_slot_unique` keeps behaving EXACTLY as before for them — the
 * new column only ever varies for a `document_types.allows_multiple` type
 * (see the companion migration), where each upload claims the next integer.
 * No existing row's meaning changes; this is purely additive.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table): void {
            $table->unsignedInteger('sequence')->default(1)->after('document_type_id');
        });

        Schema::table('documents', function (Blueprint $table): void {
            $table->dropUnique('documents_slot_unique');
            $table->unique(['documentable_type', 'documentable_id', 'document_type_id', 'sequence'], 'documents_slot_unique');
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table): void {
            $table->dropUnique('documents_slot_unique');
        });

        Schema::table('documents', function (Blueprint $table): void {
            $table->dropColumn('sequence');
            $table->unique(['documentable_type', 'documentable_id', 'document_type_id'], 'documents_slot_unique');
        });
    }
};
