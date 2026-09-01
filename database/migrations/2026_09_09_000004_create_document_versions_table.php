<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Immutable file versions of a document (M9). Every upload is a new row; a
 * verified / signed version is never overwritten or deleted. `path` is on the
 * private `documents` disk with a non-guessable component — it is NEVER
 * exposed as a URL.
 *
 * `unique(document_id, version)` + a per-document row lock make version numbers
 * concurrency-safe.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained('documents')->cascadeOnDelete();
            $table->unsignedInteger('version');

            $table->string('disk', 32)->default('documents');
            $table->string('path');
            $table->string('original_filename');
            $table->string('mime_type', 128)->nullable();
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->string('checksum', 64)->nullable(); // sha256

            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('uploaded_at');
            $table->timestamps();

            $table->unique(['document_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_versions');
    }
};
