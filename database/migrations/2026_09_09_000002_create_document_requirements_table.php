<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Configurable document checklist (M9). A row with `project_id` NULL is a
 * global rule; a project-specific row overrides it. `applies_to` decides
 * whether the requirement lands on the buyer's KYC list or the booking's list.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_requirements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->nullable()->constrained('projects')->cascadeOnDelete();
            $table->foreignId('document_type_id')->constrained('document_types')->restrictOnDelete();
            $table->string('applies_to', 12); // App\Enums\DocumentScope
            $table->boolean('required')->default(true);
            $table->unsignedInteger('sequence')->default(0);
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();

            $table->unique(['project_id', 'document_type_id', 'applies_to'], 'document_requirements_unique');
            $table->index(['applies_to', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_requirements');
    }
};
