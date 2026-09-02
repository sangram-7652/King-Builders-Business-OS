<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Possession appointment history (M10). Rescheduling supersedes the current row
 * (`superseded_at`) and inserts a new one — the full history is preserved. The
 * live appointment is the one with `superseded_at` NULL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('possession_appointments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('possession_case_id')->constrained('possession_cases')->cascadeOnDelete();
            $table->timestamp('scheduled_at');
            $table->string('site_location');
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->string('notes')->nullable();

            $table->timestamp('superseded_at')->nullable();
            $table->string('reschedule_reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['possession_case_id', 'superseded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('possession_appointments');
    }
};
