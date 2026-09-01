<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Registry appointment history (M9). Rescheduling supersedes the current row
 * (sets `superseded_at`) and inserts a new one, so the full appointment
 * history is preserved. The live appointment is the one with `superseded_at`
 * NULL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('registry_appointments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('registry_case_id')->constrained('registry_cases')->cascadeOnDelete();
            $table->timestamp('scheduled_at');
            $table->string('registry_office');
            $table->string('appointment_reference')->nullable();
            $table->foreignId('responsible_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('notes')->nullable();

            $table->timestamp('superseded_at')->nullable();
            $table->string('reschedule_reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['registry_case_id', 'superseded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('registry_appointments');
    }
};
