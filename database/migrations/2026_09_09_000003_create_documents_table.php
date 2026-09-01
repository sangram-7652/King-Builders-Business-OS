<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A managed document (M9). Polymorphic — a `documentable` is a Buyer (KYC),
 * a Booking (agreement / registry / …) or an Agreement / DocumentHandover
 * (their attached files).
 *
 * The record holds the workflow state; the actual files live in
 * `document_versions` (never destructively replaced). `current_version_id`
 * points at the latest version.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->morphs('documentable'); // buyer | booking | agreement | document_handover
            $table->foreignId('document_type_id')->constrained('document_types')->restrictOnDelete();
            $table->string('title')->nullable();
            $table->string('status', 16)->default('pending')->index(); // App\Enums\DocumentStatus

            $table->unsignedBigInteger('current_version_id')->nullable();

            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->string('rejection_reason')->nullable();
            $table->date('expires_at')->nullable();

            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['documentable_type', 'documentable_id', 'document_type_id'], 'documents_slot_unique');
            $table->index(['status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
