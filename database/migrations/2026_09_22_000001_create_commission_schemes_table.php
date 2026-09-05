<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Commission scheme versions (M14.3). Each row is ONE version of a scheme
 * family identified by `code` (CMS-000001); `(code, version)` is unique. A
 * DRAFT is editable; PUBLISHED / ARCHIVED versions are frozen for ever so a
 * commission snapshot stays reproducible. At most one PUBLISHED version per
 * `code` at a time — that is the one in force.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commission_schemes', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20);                          // CMS-000001 — family id
            $table->unsignedInteger('version')->default(1);
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('status', 12)->default('draft')->index(); // App\Enums\CommissionSchemeStatus
            $table->string('basis', 24)->default('booking_value');   // App\Enums\CommissionBasis
            $table->string('partner_type', 24)->nullable();      // App\Enums\PartnerType — auto-match, or null
            $table->boolean('is_default')->default(false);       // fallback when nothing else matches
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();

            $table->timestamp('published_at')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('archived_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['code', 'version']);
            $table->index(['status', 'partner_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_schemes');
    }
};
